<?php

use App\Filament\Resources\ExpenseResource\Pages\ManageRecords as ExpensePage;
use App\Filament\Resources\InvoiceResource\Pages\ManageRecords as InvoicePage;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\User;
use App\Services\InvoiceRegisterService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(['Accountant', 'Director']);
    $this->actingAs($this->admin);
    $this->client = Client::create(['ref_no' => '0456', 'name' => 'Register Client', 'client_type' => 'Foreign', 'country' => 'France', 'default_currency' => 'USD']);
    $para = Paralegal::create(['name' => 'Register Paralegal']);
    Matter::create(['client_id' => $this->client->id, 'paralegal_id' => $para->id, 'ref_no' => '0456', 'our_ref' => 'P26045601FR', 'matter_title' => 'Filing', 'country' => 'France', 'service_type' => 'Professional Fees']);
    $this->invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', [['matter_ref' => 'P26045601FR', 'fee_type' => 'Professional Fees', 'rate' => '100']]);
});

it('records part payments with sender advice charges and the correct remaining amount from the invoice row', function () {
    $invoice = app(InvoiceService::class)->send($this->invoice);
    Livewire::test(InvoicePage::class)->callTableAction('receive', $invoice, data: [
        'received_on' => '2026-10-20', 'amount' => '40', 'currency' => 'USD', 'bank_account_id' => $invoice->bank_account_id,
        'txn_ref' => 'receipt-one', 'charges_deducted' => '2', 'sender_name' => 'Client Treasury', 'payment_advice' => 'First instalment',
    ])->assertHasNoTableActionErrors();
    $invoice = $invoice->fresh();
    expect($invoice->status)->toBe('Partially received')->and($invoice->registerPaymentTotal())->toBe('40.00')
        ->and($invoice->outstandingAmount())->toBe('60.00')->and($invoice->registerPaymentTotal('charges_deducted'))->toBe('2.00');
    $payment = $invoice->payments()->first();
    expect($payment->sender_name)->toBe('Client Treasury')->and($payment->payment_advice)->toBe('First instalment')->and($payment->marked_by)->toBe($this->admin->id);
    Livewire::test(InvoicePage::class)->mountTableAction('payment_history', $invoice)->assertSet('mountedActions.0.name', 'payment_history');
    $history = view('filament.parts.payment-history', ['invoice' => $invoice])->render();
    expect($history)->toContain('Client Treasury', '38.00');
});

it('keeps the latest received date for backdated receipts and shares history with the tax register', function () {
    $invoice = app(InvoiceService::class)->send($this->invoice);
    $service = app(PaymentService::class);
    $common = ['currency' => 'USD', 'bank_account_id' => $invoice->bank_account_id, 'charges_deducted' => '0'];
    $service->receive($invoice, $common + ['received_on' => '2026-10-25', 'amount' => '40', 'txn_ref' => 'late'], $this->admin);
    $service->receive($invoice, $common + ['received_on' => '2026-10-20', 'amount' => '60', 'txn_ref' => 'early'], $this->admin);
    $invoice = $invoice->fresh();
    expect($invoice->received_on->toDateString())->toBe('2026-10-25');
    $tax = app(InvoiceService::class)->orderTax($invoice, '2026-10-26', $this->admin);
    expect($tax->registerPaymentTotal())->toBe('100.00')->and($tax->outstandingAmount())->toBe('0.00')->and($tax->registerPayments())->toHaveCount(2);
    expect($invoice->fresh()->taxInvoice->number)->toBe($tax->number);
});

it('edits register metadata on finalised invoices without changing the invoice or recording a payment', function () {
    $invoice = app(InvoiceService::class)->send($this->invoice);
    Livewire::test(InvoicePage::class)->callTableAction('register_details', $invoice, data: [
        'lock_version' => $invoice->lock_version, 'register_user' => 'Accounts', 'inr_exchange_rate' => '93.2088',
        'sender_name' => 'Treasury', 'register_comment' => 'Awaiting transfer', 'tds_receivable' => '0',
        'payment_intimation_date' => '2026-10-28', 'payment_informed_by' => 'Seema',
    ])->assertHasNoTableActionErrors();
    $invoice = $invoice->fresh();
    expect($invoice->registerInrAmount())->toBe('9320.88')->and($invoice->grand_total)->toBe('100.00')
        ->and($invoice->currency)->toBe('USD')->and($invoice->status)->toBe('Sent')->and($invoice->payments()->count())->toBe(0);
    expect(fn () => app(InvoiceRegisterService::class)->save($invoice, ['lock_version' => 0, 'register_user' => 'Stale'], $this->admin))->toThrow(ValidationException::class);
    app(InvoiceRegisterService::class)->save($invoice, ['lock_version' => $invoice->lock_version, 'grand_total' => '1'], $this->admin);
    expect($invoice->fresh()->grand_total)->toBe('100.00');
});

it('keeps exchange values unavailable until a rate is entered and uses one for INR invoices', function () {
    expect($this->invoice->registerInrAmount())->toBeNull();
    $this->invoice->update(['currency' => 'INR']);
    expect($this->invoice->fresh()->registerInrAmount())->toBe('100.00');
});

it('calculates Indian purchase GST split TDS and payable without modifying a foreign expense', function () {
    $base = ['expense_type' => 'vendor', 'invoice_date' => today(), 'currency' => 'INR', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->admin->id, 'category' => 'Rent', 'status' => 'Unpaid'];
    $expense = Expense::create($base + ['expense_group' => 'indian', 'taxable_amount' => '36300', 'gst_mode' => 'cgst_sgst', 'gst_rate' => '18', 'tds_rate' => '2', 'pan_number' => 'TESTPAN', 'gstin' => 'TESTGST']);
    expect($expense->amount)->toBe('42108.00')->and($expense->gst_amount)->toBe('6534.00')->and($expense->tds_amount)->toBe('726.00')
        ->and($expense->cgst)->toBe('3267.00')->and($expense->sgst)->toBe('3267.00')->and($expense->igst)->toBe('0.00');
    $expense->update(['gst_mode' => 'igst']);
    expect($expense->igst)->toBe('6534.00')->and($expense->cgst)->toBe('0.00')->and($expense->sgst)->toBe('0.00');
    $foreign = Expense::create(array_replace($base, ['expense_group' => 'foreign', 'currency' => 'JPY', 'amount' => '24390']));
    expect($foreign->amount)->toBe('24390.00');
    Livewire::test(ExpensePage::class)->set('activeTab', 'indian')->assertCanSeeTableRecords([$expense])->assertCanNotSeeTableRecords([$foreign]);
});

it('reads GST and TDS back from the database as money, not raw column values', function () {
    $expense = Expense::create([
        'expense_type' => 'vendor', 'invoice_date' => today(), 'currency' => 'INR', 'paid_by' => 'company_bank',
        'paid_by_user_id' => $this->admin->id, 'category' => 'Rent', 'status' => 'Unpaid', 'expense_group' => 'indian',
        'taxable_amount' => '10000', 'gst_mode' => 'igst', 'gst_rate' => '18', 'tds_rate' => '10',
    ]);

    $stored = $expense->fresh();

    expect($stored->taxable_amount)->toBe('10000.00')
        ->and($stored->gst_amount)->toBe('1800.00')
        ->and($stored->igst)->toBe('1800.00')
        ->and($stored->cgst)->toBe('0.00')
        ->and($stored->sgst)->toBe('0.00')
        ->and($stored->tds_amount)->toBe('1000.00')
        ->and($stored->amount)->toBe('10800.00')
        ->and($stored->tds_applicable)->toBeTrue()
        ->and($stored->requires_15ca_cb)->toBeFalse();
});

it('records the transfer bank and date when paying an expense from the register', function () {
    $expense = Expense::create(['expense_type' => 'vendor', 'invoice_date' => today(), 'amount' => '50', 'currency' => 'USD', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->admin->id, 'category' => 'Law firm', 'expense_group' => 'foreign', 'status' => 'Unpaid']);
    Livewire::test(ExpensePage::class)->callTableAction('paid', $expense, data: ['paid_on' => '2026-10-28', 'paid_ref' => 'wire-123', 'payment_bank_name' => 'Company Bank'])->assertHasNoTableActionErrors();
    expect($expense->fresh()->status)->toBe('Paid')->and($expense->fresh()->payment_bank_name)->toBe('Company Bank');
});

it('keeps receipt and register editing permissions separate', function () {
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole('Director');
    expect(fn () => app(InvoiceRegisterService::class)->save($this->invoice, ['lock_version' => 0], $actor))->toThrow(HttpException::class);
    $invoice = app(InvoiceService::class)->send($this->invoice);
    $assistant = User::factory()->create(['is_active' => true]);
    $assistant->assignRole('Accountant Assistant');
    expect(fn () => app(PaymentService::class)->receive($invoice, [], $assistant))->toThrow(HttpException::class);
});

it('filters received pending and Indian sale registers without mixing documents', function () {
    $sent = app(InvoiceService::class)->send($this->invoice);
    $indian = Client::create(['name' => 'Indian Register Client', 'ref_no' => '0457', 'client_type' => 'Indian', 'country' => 'India']);
    $tax = $sent->replicate(['number']);
    $tax->fill(['doc_type' => 'tax', 'client_id' => $indian->id, 'status' => 'Issued', 'number' => 'TESTTAX']);
    $tax->save();
    Livewire::test(InvoicePage::class)->set('activeTab', 'indian_sale')->assertCanSeeTableRecords([$tax])->assertCanNotSeeTableRecords([$sent])
        ->set('activeTab', 'pending')->assertCanSeeTableRecords([$sent])->assertCanNotSeeTableRecords([$tax]);
});
