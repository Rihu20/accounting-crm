<?php

use App\Filament\Resources\InvoiceResource\Pages\ManageRecords as InvoicePage;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $role = function (string $name): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($name);

        return $user;
    };
    $this->accountant = $role('Accountant');
    $this->director = $role('Director');
    $this->foreign = Client::create(['ref_no' => '0701', 'name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    $this->indian = Client::create(['ref_no' => '0702', 'name' => 'Desi Pvt Ltd', 'client_type' => 'Indian', 'country' => 'India', 'state' => 'Maharashtra', 'gstin' => '27AABCU9603R1ZM', 'default_currency' => 'INR']);
    $paralegal = Paralegal::create(['name' => 'Seema']);
    Matter::create(['client_id' => $this->foreign->id, 'paralegal_id' => $paralegal->id, 'ref_no' => '0701', 'our_ref' => 'P26070101DE', 'matter_title' => 'Filing', 'country' => 'Germany', 'service_type' => 'Professional Fees']);
    Matter::create(['client_id' => $this->indian->id, 'paralegal_id' => $paralegal->id, 'ref_no' => '0702', 'our_ref' => 'P26070201IN', 'matter_title' => 'Design', 'country' => 'India', 'service_type' => 'Professional Fees']);
    $this->actingAs($this->accountant);
    $this->invoices = app(InvoiceService::class);
    $this->payments = app(PaymentService::class);
    $this->sent = $this->invoices->send($this->invoices->draft($this->accountant, $this->foreign->id, 'USD', [['matter_ref' => 'P26070101DE', 'fee_type' => 'Professional Fees', 'rate' => '1000']]));
    $this->pay = fn (array $data = [], ?Invoice $invoice = null, ?User $actor = null) => $this->payments->receive($invoice ?? $this->sent, $data + ['received_on' => today()->toDateString(), 'amount' => '400', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'SWIFT-1'], $actor ?? $this->accountant);
});

it('refuses the same transaction reference twice on one invoice, whatever its case or spacing', function () {
    ($this->pay)();
    expect(fn () => ($this->pay)(['amount' => '100', 'txn_ref' => ' swift-1 ']))->toThrow(HttpException::class, 'already recorded');
    expect($this->sent->fresh()->registerPaymentTotal())->toBe('400.00');
});

it('accepts the reference again after the first entry is reversed, and on another invoice', function () {
    ($this->pay)();
    $other = $this->invoices->send($this->invoices->draft($this->accountant, $this->foreign->id, 'USD', [['matter_ref' => 'P26070101DE', 'fee_type' => 'Professional Fees', 'rate' => '500']]));
    ($this->pay)(['amount' => '500'], $other);
    expect($other->fresh()->status)->toBe('Received');

    $this->payments->reversePayment($this->sent->payments()->first(), 'Wrong invoice', $this->accountant);
    ($this->pay)(['amount' => '250']);
    expect($this->sent->fresh()->registerPaymentTotal())->toBe('250.00');
});

it('refuses a payment dated in the future', function () {
    expect(fn () => ($this->pay)(['received_on' => today()->addDay()->toDateString()]))->toThrow(ValidationException::class);
    expect($this->sent->fresh()->status)->toBe('Sent');
});

it('keeps the rupees credited for a foreign payment and ignores them on a rupee invoice', function () {
    ($this->pay)(['inr_amount' => '33400.50']);
    expect($this->sent->payments()->first()->inr_amount)->toBe('33400.50');
    expect(view('filament.parts.payment-history', ['invoice' => $this->sent->fresh()])->render())->toContain('INR credited', '33,400.50');

    $inr = $this->invoices->send($this->invoices->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26070201IN', 'fee_type' => 'Professional Fees', 'rate' => '10000']]));
    ($this->pay)(['amount' => '11800', 'currency' => 'INR', 'bank_account_id' => BankAccount::where('currency', 'INR')->first()->id, 'inr_amount' => '11800'], $inr);
    expect($inr->payments()->first()->inr_amount)->toBeNull();
});

it('reverses one payment entry and leaves the others, with the status following the balance', function () {
    ($this->pay)(['amount' => '400', 'txn_ref' => 'A']);
    ($this->pay)(['amount' => '600', 'txn_ref' => 'B']);
    expect($this->sent->fresh()->status)->toBe('Received');
    $second = $this->sent->payments()->where('txn_ref', 'B')->first();

    $this->payments->reversePayment($second, 'Bank recalled the transfer', $this->director);
    $invoice = $this->sent->fresh();
    expect($invoice->status)->toBe('Partially received')->and($invoice->payment_status)->toBe('Partially received')
        ->and($invoice->outstandingAmount())->toBe('600.00')->and($invoice->registerPayments())->toHaveCount(1)
        ->and($invoice->tax_authorization)->toBeNull();
    expect(Activity::where('description', 'Payment entry reversed')->latest('id')->first()->properties['reason'])->toBe('Bank recalled the transfer');
    expect(fn () => $this->payments->reversePayment($second, 'Again', $this->director))->toThrow(HttpException::class);

    $this->payments->reversePayment($this->sent->payments()->where('txn_ref', 'A')->first(), 'Entered on the wrong proforma', $this->director);
    $invoice = $this->sent->fresh();
    expect($invoice->status)->toBe('Sent')->and($invoice->payment_status)->toBe('Pending')->and($invoice->received_on)->toBeNull();
});

it('does not reverse a payment once the tax invoice is raised, or without a reason or the right role', function () {
    ($this->pay)(['amount' => '1000']);
    $payment = $this->sent->payments()->first();
    $assistant = User::factory()->create(['is_active' => true]);
    $assistant->assignRole('Accountant Assistant');
    expect(fn () => $this->payments->reversePayment($payment, 'x', $assistant))->toThrow(HttpException::class);
    expect(fn () => $this->payments->reversePayment($payment, '  ', $this->accountant))->toThrow(HttpException::class);

    $this->invoices->orderTax($this->sent->fresh(), today()->toDateString(), $this->accountant);
    expect(fn () => $this->payments->reversePayment($payment, 'Too late', $this->accountant))->toThrow(HttpException::class, 'credit note');
    expect((bool) $payment->fresh()->reversed)->toBeFalse();
});

it('offers the reverse action on a paid proforma', function () {
    ($this->pay)();
    Livewire::test(InvoicePage::class)->assertTableActionVisible('reverse_payment', $this->sent->fresh());
});

it('shows why a payment was refused on the screen and keeps the form open', function () {
    ($this->pay)();
    Livewire::test(InvoicePage::class)
        ->callTableAction('receive', $this->sent->fresh(), ['received_on' => today()->toDateString(), 'amount' => '100', 'tds_amount' => '0', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'swift-1', 'charges_deducted' => '0'])
        ->assertNotified('Transaction reference swift-1 is already recorded on this invoice. Reverse that entry first if it was wrong.')
        ->assertSet('mountedActions.0.name', 'receive');
    expect($this->sent->fresh()->registerPaymentTotal())->toBe('400.00');
});

it('lets the accountant write off a short payment within the limit only', function () {
    ($this->pay)(['amount' => '960']);
    $this->payments->writeOff($this->sent->fresh(), 'Bank charges (SHA)', $this->accountant);
    expect($this->sent->fresh()->status)->toBe('Received')->and($this->sent->fresh()->registerPaymentTotal('write_off_amount'))->toBe('40.00');
});

it('leaves a write-off above the limit to the Director', function () {
    ($this->pay)(['amount' => '400']);
    expect(PaymentService::writeOffLimit($this->sent->fresh()))->toBe('50.00');
    expect(fn () => $this->payments->writeOff($this->sent->fresh(), 'Client will not pay the rest', $this->accountant))->toThrow(HttpException::class, 'Only the Director');
    expect($this->sent->fresh()->status)->toBe('Partially received');

    $this->payments->writeOff($this->sent->fresh(), 'Agreed discount', $this->director);
    expect($this->sent->fresh()->status)->toBe('Received')->and($this->sent->fresh()->registerPaymentTotal('write_off_amount'))->toBe('600.00');
});

it('reads the write-off limit from settings', function () {
    Setting::updateOrCreate(['key' => 'write_off_limit_percent'], ['value' => '50']);
    ($this->pay)(['amount' => '600']);
    $this->payments->writeOff($this->sent->fresh(), 'Agreed', $this->accountant);
    expect($this->sent->fresh()->status)->toBe('Received');
    expect(fn () => Setting::updateOrCreate(['key' => 'write_off_limit_percent'], ['value' => 'lots']))->toThrow(ValidationException::class);
});

it('refuses a tax invoice dated in the future or before the payment', function () {
    ($this->pay)(['amount' => '1000', 'received_on' => today()->subDays(3)->toDateString()]);
    expect(fn () => $this->invoices->orderTax($this->sent->fresh(), today()->addDay()->toDateString(), $this->accountant))->toThrow(ValidationException::class);
    expect(fn () => $this->invoices->orderTax($this->sent->fresh(), today()->subDays(4)->toDateString(), $this->accountant))->toThrow(HttpException::class, 'before the payment date');
    expect(Invoice::where('doc_type', 'tax')->count())->toBe(0);

    $tax = $this->invoices->orderTax($this->sent->fresh(), today()->subDays(3)->toDateString(), $this->accountant);
    expect($tax->invoice_date->toDateString())->toBe(today()->subDays(3)->toDateString());
});

it('rounds a rupee invoice to the nearest rupee and shows the round off on the PDF', function () {
    Setting::updateOrCreate(['key' => 'round_off_inr'], ['value' => 'yes']);
    $inr = $this->invoices->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26070201IN', 'fee_type' => 'Professional Fees', 'rate' => '1000.40']]);
    expect($inr->tax_total)->toBe('180.07')->and($inr->grand_total)->toBe('1180.00')->and($inr->round_off)->toBe('-0.47')
        ->and($inr->amount_in_words)->not->toContain('/100');

    $up = $this->invoices->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26070201IN', 'fee_type' => 'Professional Fees', 'rate' => '1000.50']]);
    expect($up->grand_total)->toBe('1181.00')->and($up->round_off)->toBe('0.41');

    $sent = $this->invoices->send($inr);
    expect(view('pdf.invoice', ['invoice' => $sent, 'pages' => app(PdfService::class)->pages($sent), 'settings' => Setting::pluck('value', 'key')->all()])->render())->toContain('Round off', '-0.47');
    ($this->pay)(['amount' => '1180', 'currency' => 'INR', 'bank_account_id' => BankAccount::where('currency', 'INR')->first()->id], $sent);
    $tax = $this->invoices->orderTax($sent->fresh(), today()->toDateString(), $this->accountant);
    expect($tax->grand_total)->toBe('1180.00')->and($tax->round_off)->toBe('-0.47');
});

it('leaves rupee invoices to the paisa until rounding is switched on, and never rounds foreign invoices', function () {
    $inr = $this->invoices->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26070201IN', 'fee_type' => 'Professional Fees', 'rate' => '1000.40']]);
    expect($inr->grand_total)->toBe('1180.47')->and($inr->round_off)->toBe('0.00');

    Setting::updateOrCreate(['key' => 'round_off_inr'], ['value' => 'yes']);
    $usd = $this->invoices->draft($this->accountant, $this->foreign->id, 'USD', [['matter_ref' => 'P26070101DE', 'fee_type' => 'Professional Fees', 'rate' => '1000.40']]);
    expect($usd->grand_total)->toBe('1000.40')->and($usd->round_off)->toBe('0.00');

    Setting::updateOrCreate(['key' => 'round_off_inr'], ['value' => 'no']);
    $inr = $this->invoices->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26070201IN', 'fee_type' => 'Professional Fees', 'rate' => '1000.40']]);
    expect($inr->grand_total)->toBe('1180.47')->and($inr->round_off)->toBe('0.00');
});
