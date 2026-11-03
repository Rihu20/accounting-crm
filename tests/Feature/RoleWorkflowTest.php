<?php

use App\Filament\Resources\ExpenseResource\Pages\ManageRecords as ExpensePage;
use App\Filament\Resources\InvoiceResource\Pages\ManageRecords as InvoicePage;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceNote;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\NoteService;
use App\Services\PaymentService;
use App\Services\PdfService;
use App\Services\PortalWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function crmUser(string $role): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($role);

    return $u;
}

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->accountant = crmUser('Accountant');
    $this->assistant = crmUser('Accountant Assistant');
    $this->director = crmUser('Director');
    $this->paralegal = crmUser('Paralegal');
    $this->client = Client::create(['ref_no' => '0777', 'name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    $this->indian = Client::create(['ref_no' => '0888', 'name' => 'Desi Pvt Ltd', 'client_type' => 'Indian', 'country' => 'India', 'state' => 'Maharashtra', 'gstin' => '27AABCU9603R1ZM', 'default_currency' => 'INR']);
    $para = Paralegal::create(['name' => 'Seema', 'user_id' => $this->paralegal->id]);
    Matter::create(['client_id' => $this->client->id, 'paralegal_id' => $para->id, 'ref_no' => '0777', 'our_ref' => 'P26077701DE', 'matter_title' => 'Filing', 'country' => 'Germany', 'service_type' => 'Professional Fees']);
    Matter::create(['client_id' => $this->indian->id, 'paralegal_id' => $para->id, 'ref_no' => '0888', 'our_ref' => 'P26088801IN', 'matter_title' => 'Design', 'country' => 'India', 'service_type' => 'Professional Fees']);
    $this->actingAs($this->assistant);
    $this->sent = app(InvoiceService::class)->send(app(InvoiceService::class)->draft($this->assistant, $this->client->id, 'USD', [['matter_ref' => 'P26077701DE', 'fee_type' => 'Professional Fees', 'rate' => '1000']]));
});

it('gives each of the four roles exactly the access described', function () {
    $a = fn (User $u, string $p) => $u->allowed($p);
    // Accountant: everything
    expect(collect(App\Services\PlatformAccess::permissions())->every(fn ($p) => $a($this->accountant, $p)))->toBeTrue();
    // Assistant: prepares, never sees or changes payments, never approves
    expect($a($this->assistant, 'accounting.invoices.create'))->toBeTrue()
        ->and($a($this->assistant, 'accounting.notes.create'))->toBeTrue()
        ->and($a($this->assistant, 'accounting.expenses.create'))->toBeTrue()
        ->and($a($this->assistant, 'payments.view'))->toBeFalse()
        ->and($a($this->assistant, 'payments.mark_received'))->toBeFalse()
        ->and($a($this->assistant, 'accounting.tax.approve'))->toBeFalse()
        ->and($a($this->assistant, 'requests.review'))->toBeFalse();
    // Director: sees all, changes payment status, approves – cannot create
    expect($a($this->director, 'payments.mark_received'))->toBeTrue()
        ->and($a($this->director, 'accounting.expenses.pay'))->toBeTrue()
        ->and($a($this->director, 'accounting.tax.approve'))->toBeTrue()
        ->and($a($this->director, 'requests.review'))->toBeTrue()
        ->and($a($this->director, 'accounting.invoices.create'))->toBeFalse()
        ->and($a($this->director, 'accounting.expenses.create'))->toBeFalse()
        ->and($a($this->director, 'accounting.tax.issue'))->toBeFalse();
    // Paralegal: view + requests only
    expect($a($this->paralegal, 'accounting.invoices.view'))->toBeTrue()
        ->and($a($this->paralegal, 'requests.create'))->toBeTrue()
        ->and($a($this->paralegal, 'accounting.invoices.create'))->toBeFalse()
        ->and($a($this->paralegal, 'payments.mark_received'))->toBeFalse()
        ->and($a($this->paralegal, 'requests.review'))->toBeFalse();
});

it('opens the right pages for every role and blocks the rest', function () {
    $pages = [
        'accountant' => ['ok' => ['/admin', '/admin/invoices', '/admin/expenses', '/admin/invoice-notes', '/admin/users', '/admin/clients', '/admin/reports', '/admin/settings'], 'no' => []],
        'assistant' => ['ok' => ['/admin', '/admin/invoices', '/admin/expenses', '/admin/invoice-notes', '/admin/clients', '/admin/word-upload'], 'no' => ['/admin/users', '/admin/settings', '/admin/reports']],
        'director' => ['ok' => ['/admin', '/admin/invoices', '/admin/expenses', '/admin/invoice-notes', '/admin/reports'], 'no' => ['/admin/clients', '/admin/word-upload', '/admin/users', '/admin/settings']],
        'paralegal' => ['ok' => ['/admin', '/admin/invoices', '/admin/expenses'], 'no' => ['/admin/clients', '/admin/word-upload', '/admin/invoice-notes', '/admin/users', '/admin/reports']],
    ];
    foreach ($pages as $role => $set) {
        $this->actingAs($this->{$role});
        foreach ($set['ok'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach ($set['no'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }
    $this->get('/portal/invoices')->assertRedirect('/admin');
});

it('hides payment information from the assistant', function () {
    app(PaymentService::class)->receive($this->sent, ['received_on' => today()->toDateString(), 'amount' => '400', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'T1'], $this->director);
    $this->actingAs($this->assistant);
    Livewire::test(InvoicePage::class)
        ->assertTableColumnHidden('register_received')
        ->assertTableColumnHidden('register_remaining')
        ->assertTableActionHidden('receive', $this->sent->fresh())
        ->assertTableActionHidden('payment_history', $this->sent->fresh())
        ->assertDontSee('Partially received');
    $this->actingAs($this->director);
    Livewire::test(InvoicePage::class)->assertTableColumnVisible('register_received')->assertSee('Partially received');
});

it('runs assistant request → director approval → assistant tax invoice', function () {
    $this->actingAs($this->assistant);
    app(PortalWorkflow::class)->requestTax($this->sent, $this->assistant);
    // Assistant cannot approve and cannot generate yet
    expect(fn () => app(PortalWorkflow::class)->decideTax($this->sent, $this->assistant, true))->toThrow(HttpException::class);
    // Director cannot approve before full payment
    expect(fn () => app(PortalWorkflow::class)->decideTax($this->sent, $this->director, true))->toThrow(HttpException::class);
    app(PaymentService::class)->receive($this->sent, ['received_on' => today()->toDateString(), 'amount' => '1000', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'T1'], $this->director);
    expect(fn () => app(InvoiceService::class)->orderTax($this->sent->fresh(), today()->toDateString(), $this->assistant))->toThrow(HttpException::class);
    app(PortalWorkflow::class)->decideTax($this->sent->fresh(), $this->director, true);
    $tax = app(InvoiceService::class)->orderTax($this->sent->fresh(), today()->toDateString(), $this->assistant);
    expect($tax->doc_type)->toBe('tax')->and($tax->status)->toBe('Issued')->and($this->sent->fresh()->status)->toBe('Converted');
    // Director can never issue
    expect($this->director->allowed('accounting.tax.issue'))->toBeFalse();
});

it('lets the accountant issue a tax invoice directly once paid', function () {
    app(PaymentService::class)->receive($this->sent, ['received_on' => today()->toDateString(), 'amount' => '1000', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'T1'], $this->accountant);
    $tax = app(InvoiceService::class)->orderTax($this->sent->fresh(), today()->toDateString(), $this->accountant);
    expect($tax->number)->toStartWith('AIP');
});

it('lets the paralegal only raise requests that directors or accountants review', function () {
    $this->actingAs($this->paralegal);
    app(PortalWorkflow::class)->reportInvoicePayment($this->sent, $this->paralegal, 'Wire sent 02.11');
    expect($this->sent->fresh()->payment_initiation['status'])->toBe('Pending');
    expect(fn () => app(PaymentService::class)->receive($this->sent, ['received_on' => today()->toDateString(), 'amount' => '10', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'X'], $this->paralegal))->toThrow(HttpException::class);
    expect(fn () => app(PortalWorkflow::class)->reviewInvoicePayment($this->sent, $this->assistant, true))->toThrow(HttpException::class);
    app(PortalWorkflow::class)->reviewInvoicePayment($this->sent, $this->director, true);
    expect($this->sent->fresh()->payment_initiation['status'])->toBe('Approved');

    $firm = Expense::create(['expense_type' => 'vendor', 'expense_group' => 'foreign', 'payee_name' => 'Smith LLP', 'category' => 'Law firm', 'invoice_date' => today(), 'amount' => '500', 'currency' => 'USD', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->accountant->id, 'status' => 'Unpaid']);
    $indian = Expense::create(['expense_type' => 'vendor', 'expense_group' => 'indian', 'payee_name' => 'Rent', 'category' => 'Rent', 'invoice_date' => today(), 'amount' => '500', 'currency' => 'INR', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->accountant->id, 'status' => 'Unpaid']);
    Livewire::test(ExpensePage::class)->assertCanSeeTableRecords([$firm])->assertCanNotSeeTableRecords([$indian])->assertTableActionHidden('edit', $firm)->assertTableActionHidden('paid', $firm)
        ->callTableAction('request_payment', $firm, data: ['note' => 'Firm chasing'])->assertHasNoTableActionErrors();
    expect($firm->fresh()->payment_request['status'])->toBe('Pending');
    // Director pays it – that also approves the request
    $this->actingAs($this->director);
    Livewire::test(ExpensePage::class)->callTableAction('paid', $firm, data: ['paid_on' => today()->toDateString(), 'paid_ref' => 'W-1'])->assertHasNoTableActionErrors();
    expect($firm->fresh()->status)->toBe('Paid')->and($firm->fresh()->payment_request['status'])->toBe('Approved');
    Livewire::test(ExpensePage::class)->callTableAction('unpaid', $firm, data: ['reason' => 'Bounced'])->assertHasNoTableActionErrors();
    expect($firm->fresh()->status)->toBe('Unpaid');
});

it('settles an Indian invoice with TDS and closes a foreign short payment with a write-off', function () {
    $this->actingAs($this->assistant);
    $inr = app(InvoiceService::class)->send(app(InvoiceService::class)->draft($this->assistant, $this->indian->id, 'INR', [['matter_ref' => 'P26088801IN', 'fee_type' => 'Professional Fees', 'rate' => '10000']], null, 'india_gst'));
    expect($inr->grand_total)->toBe('11800.00');
    $bank = BankAccount::where('currency', 'INR')->first()->id;
    app(PaymentService::class)->receive($inr, ['received_on' => today()->toDateString(), 'amount' => '10800', 'tds_amount' => '1000', 'currency' => 'INR', 'bank_account_id' => $bank, 'txn_ref' => 'NEFT1'], $this->director);
    expect($inr->fresh()->status)->toBe('Received')->and($inr->fresh()->outstandingAmount())->toBe('0.00');

    app(PaymentService::class)->receive($this->sent, ['received_on' => today()->toDateString(), 'amount' => '975', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'SWIFT'], $this->director);
    expect($this->sent->fresh()->status)->toBe('Partially received');
    app(PaymentService::class)->writeOff($this->sent, 'Intermediary bank charges (SHA)', $this->director);
    expect($this->sent->fresh()->status)->toBe('Received')->and($this->sent->fresh()->registerPaymentTotal('write_off_amount'))->toBe('25.00');
});

it('raises numbered credit and debit notes with the correct GST split', function () {
    $this->actingAs($this->accountant);
    $inr = app(InvoiceService::class)->send(app(InvoiceService::class)->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26088801IN', 'fee_type' => 'Professional Fees', 'rate' => '10000']], null, 'india_gst'));
    app(PaymentService::class)->receive($inr, ['received_on' => today()->toDateString(), 'amount' => '11800', 'currency' => 'INR', 'bank_account_id' => BankAccount::where('currency', 'INR')->first()->id, 'txn_ref' => 'N'], $this->accountant);
    $tax = app(InvoiceService::class)->orderTax($inr->fresh(), today()->toDateString(), $this->accountant);

    $this->actingAs($this->assistant);
    $cn = app(NoteService::class)->create($tax, ['type' => 'credit', 'note_date' => today()->toDateString(), 'taxable_amount' => '1000', 'reason' => 'Discount'], $this->assistant);
    // Maharashtra client vs UP GSTIN → IGST
    expect($cn->number)->toStartWith('CN')->and($cn->igst)->toBe('180.00')->and($cn->cgst)->toBe('0.00')->and($cn->amount)->toBe('1180.00');
    $dn = app(NoteService::class)->create($tax, ['type' => 'debit', 'note_date' => today()->toDateString(), 'non_taxable_amount' => '50', 'reason' => 'Extra official fee'], $this->assistant);
    expect($dn->number)->toStartWith('DN')->and($dn->amount)->toBe('50.00');
    Storage::disk('local')->assertExists($cn->pdf_path);
    expect(fn () => app(NoteService::class)->create($tax, ['type' => 'credit', 'note_date' => today()->toDateString(), 'taxable_amount' => '20000', 'reason' => 'Too much'], $this->assistant))->toThrow(HttpException::class);
    expect(fn () => app(NoteService::class)->create($tax, ['type' => 'credit', 'note_date' => today()->toDateString(), 'taxable_amount' => '1', 'reason' => 'x'], $this->director))->toThrow(HttpException::class);

    $this->indian->update(['state' => 'Uttar Pradesh', 'gstin' => '09AAACX1234X1Z5']);
    $intra = app(NoteService::class)->create($tax->fresh(), ['type' => 'credit', 'note_date' => today()->toDateString(), 'taxable_amount' => '100', 'reason' => 'Intra'], $this->assistant);
    expect($intra->cgst)->toBe('9.00')->and($intra->sgst)->toBe('9.00')->and($intra->igst)->toBe('0.00');
    $this->get('/crm/notes/'.$cn->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(InvoiceNote::count())->toBe(3);
    Livewire::test(App\Filament\Resources\InvoiceNoteResource\Pages\ManageRecords::class)->assertCanSeeTableRecords(InvoiceNote::all());
    foreach (['accountant', 'assistant', 'director', 'paralegal'] as $role) {
        $this->actingAs($this->{$role})->get('/admin')->assertOk();
        $this->get('/admin/invoices')->assertOk();
    }
});

it('prints GSTIN, place of supply and the LUT declaration', function () {
    Setting::updateOrCreate(['key' => 'lut_arn'], ['value' => 'AD0904260012345']);
    app(PaymentService::class)->receive($this->sent, ['received_on' => today()->toDateString(), 'amount' => '1000', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'T'], $this->accountant);
    $tax = app(InvoiceService::class)->orderTax($this->sent->fresh(), today()->toDateString(), $this->accountant);
    $html = view('pdf.invoice', ['invoice' => $tax->load('client'), 'pages' => app(PdfService::class)->pages($tax), 'settings' => Setting::pluck('value', 'key')->all()])->render();
    expect($html)->toContain('Place of Supply: Outside India')->toContain('under LUT (ARN AD0904260012345)');

    $this->actingAs($this->accountant);
    $inr = app(InvoiceService::class)->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26088801IN', 'fee_type' => 'Professional Fees', 'rate' => '100']], null, 'india_gst');
    $html = view('pdf.invoice', ['invoice' => $inr->load('client'), 'pages' => app(PdfService::class)->pages($inr), 'settings' => Setting::pluck('value', 'key')->all()])->render();
    expect($html)->toContain('GSTIN: 27AABCU9603R1ZM')->toContain('Place of Supply: Maharashtra (27)')->toContain('IGST');
});

it('blocks finalising an Indian invoice when the client state is missing', function () {
    $this->indian->update(['state' => null]);
    $draft = app(InvoiceService::class)->draft($this->assistant, $this->indian->id, 'INR', [['matter_ref' => 'P26088801IN', 'fee_type' => 'Professional Fees', 'rate' => '100']], null, 'india_gst');
    expect(fn () => app(InvoiceService::class)->send($draft))->toThrow(HttpException::class);
});

it('numbers invoices per financial year', function () {
    $svc = app(InvoiceService::class);
    expect($svc->nextNumber('proforma', '2027-03-31', 'PI'))->toStartWith('PI26')
        ->and($svc->nextNumber('proforma', '2027-04-01', 'PI'))->toBe('PI270001');
});

it('stops the director and paralegal from creating invoices or expenses', function () {
    foreach ([$this->director, $this->paralegal] as $user) {
        expect(fn () => app(InvoiceService::class)->draft($user, $this->client->id, 'USD', [['matter_ref' => 'P26077701DE', 'fee_type' => 'Professional Fees', 'rate' => '1']]))->toThrow(HttpException::class);
        $this->actingAs($user);
        expect(App\Filament\Resources\ExpenseResource::canCreate())->toBeFalse();
        $user->is($this->director) ? Livewire::test(InvoicePage::class)->assertActionHidden('create') : Livewire::test(InvoicePage::class)->assertActionDoesNotExist('create');
    }
});

it('builds the reports: ageing, GSTR-1 working and reminder text', function () {
    $this->actingAs($this->accountant);
    $inr = app(InvoiceService::class)->send(app(InvoiceService::class)->draft($this->accountant, $this->indian->id, 'INR', [['matter_ref' => 'P26088801IN', 'fee_type' => 'Professional Fees', 'rate' => '10000'], ['matter_ref' => 'P26088801IN', 'fee_type' => 'Official Fees', 'rate' => '2000']], null, 'india_gst'));
    $ageing = app(App\Services\ReportService::class)->ageing();
    expect($ageing['USD']['0-30'])->toBe('1000.00')->and($ageing['INR']['total'])->toBe('13800.00');

    app(PaymentService::class)->receive($inr, ['received_on' => today()->toDateString(), 'amount' => '13800', 'currency' => 'INR', 'bank_account_id' => BankAccount::where('currency', 'INR')->first()->id, 'txn_ref' => 'N'], $this->accountant);
    $tax = app(InvoiceService::class)->orderTax($inr->fresh(), today()->toDateString(), $this->accountant);
    app(PaymentService::class)->receive($this->sent, ['received_on' => today()->toDateString(), 'amount' => '1000', 'currency' => 'USD', 'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'S'], $this->accountant);
    $export = app(InvoiceService::class)->orderTax($this->sent->fresh(), today()->toDateString(), $this->accountant);
    $export->update(['inr_exchange_rate' => '84.5']);
    app(NoteService::class)->create($tax, ['type' => 'credit', 'note_date' => today()->toDateString(), 'taxable_amount' => '1000', 'reason' => 'Discount'], $this->accountant);

    $g = app(App\Services\ReportService::class)->gstr1(today()->format('Y-m'));
    $rows = collect($g['Invoices'])->slice(1)->keyBy(1);
    expect($rows[$tax->number][0])->toBe('B2B')->and($rows[$tax->number][10])->toBe('10000.00')->and($rows[$tax->number][11])->toBe('2000.00')->and($rows[$tax->number][13])->toBe('1800.00')
        ->and($rows[$export->number][0])->toBe('EXPWOP')->and($rows[$export->number][9])->toBe('84500.00');
    expect($g['Credit-Debit Notes'])->toHaveCount(2);

    $this->get('/crm/reports/gstr1?month='.today()->format('Y-m'))->assertDownload();
    $this->get('/crm/reports/ageing')->assertDownload();
    $this->get('/admin/reports')->assertOk()->assertSee('Receivables ageing');
    $this->actingAs($this->assistant)->get('/crm/reports/gstr1?month='.today()->format('Y-m'))->assertForbidden();

    $open = app(InvoiceService::class)->send(app(InvoiceService::class)->draft($this->accountant, $this->client->id, 'USD', [['matter_ref' => 'P26077701DE', 'fee_type' => 'Professional Fees', 'rate' => '250']]));
    expect(App\Filament\Resources\InvoiceResource::reminderText($open))->toContain($open->number)->toContain('$250.00 USD');
    $this->actingAs($this->director);
    Livewire::test(InvoicePage::class)->assertTableActionVisible('reminder', $open);
    expect(view('filament.parts.reminder', ['invoice' => $open, 'text' => 'x'])->render())->toContain('Copy email');
    $this->actingAs($this->assistant);
    Livewire::test(InvoicePage::class)->assertTableActionHidden('reminder', $open);
});
