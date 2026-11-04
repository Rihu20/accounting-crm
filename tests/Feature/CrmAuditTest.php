<?php

use App\Exports\RowsExport;
use App\Filament\Resources\ExpenseResource\Pages\ManageRecords as ExpensePage;
use App\Filament\Resources\InvoiceDocumentResource\Pages\ManageRecords as LibraryPage;
use App\Filament\Resources\InvoiceResource\Pages\ManageRecords as InvoicePage;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\InvoiceService;
use App\Services\MasterImportService;
use App\Services\OptimisticUpdate;
use App\Services\PaymentService;
use App\Services\WordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('Accountant');
    $this->actingAs($this->admin);
    $this->client = Client::create(['name' => 'Audit Client', 'ref_no' => '1248', 'client_type' => 'Foreign', 'country' => 'France', 'default_currency' => 'USD']);
    $para = Paralegal::create(['name' => 'Audit Paralegal']);
    Matter::create(['client_id' => $this->client->id, 'paralegal_id' => $para->id, 'ref_no' => '1248', 'our_ref' => 'P25124809KR', 'matter_title' => 'Filing', 'country' => 'Korea', 'service_type' => 'Professional Fees']);
    $this->invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', [['matter_ref' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => '100']]);
});
it('switches invoice and uploaded PDF tabs without crashing or mixing types', function () {
    $tax = $this->invoice->replicate();
    $tax->doc_type = 'tax';
    $tax->save();
    Livewire::test(InvoicePage::class)->set('activeTab', 'tax')->assertCanSeeTableRecords([$tax])->assertCanNotSeeTableRecords([$this->invoice])->set('activeTab', 'proforma')->assertCanSeeTableRecords([$this->invoice])->assertCanNotSeeTableRecords([$tax]);
    $doc = InvoiceDocument::factory()->create(['uploaded_by' => $this->admin->id, 'doc_type' => 'tax']);
    Livewire::test(LibraryPage::class)->set('activeTab', 'tax')->assertCanSeeTableRecords([$doc])->set('activeTab', 'proforma')->assertCanNotSeeTableRecords([$doc]);
});
it('switches all expense group tabs including Indian admin', function () {
    $base = ['expense_type' => 'owner', 'invoice_date' => today(), 'amount' => 10, 'currency' => 'JPY', 'paid_by' => 'cash', 'paid_by_user_id' => $this->admin->id, 'status' => 'Paid', 'reimbursement_status' => 'Pending'];
    $foreign = Expense::create($base + ['expense_group' => 'foreign', 'category' => 'Law firm']);
    $indian = Expense::create($base + ['expense_group' => 'indian', 'category' => 'Admin expense']);
    Livewire::test(ExpensePage::class)->set('activeTab', 'foreign')->assertCanSeeTableRecords([$foreign])->assertCanNotSeeTableRecords([$indian])->set('activeTab', 'patent_office')->assertCanNotSeeTableRecords([$foreign, $indian])->set('activeTab', 'indian')->assertCanSeeTableRecords([$indian])->set('activeTab', 'indian_admin')->assertCanSeeTableRecords([$indian])->assertCanNotSeeTableRecords([$foreign]);
    $this->get('/admin/reports')->assertOk()->assertSee('Receivables ageing')->assertSee('GSTR-1');
});
it('requires a payment destination and rejects inactive gateways', function () {
    $approver = User::factory()->create(['is_active' => true]);
    $approver->assignRole('Director');
    $invoice = app(InvoiceService::class)->send($this->invoice);
    $data = ['amount' => '100.00', 'currency' => 'USD', 'txn_ref' => 'audit-payment', 'received_on' => today()->toDateString()];
    expect(fn () => app(PaymentService::class)->receive($invoice, $data, $approver))->toThrow(ValidationException::class);
    $gateway = PaymentGateway::create(['name' => 'Inactive gateway', 'is_active' => false]);
    try {
        app(PaymentService::class)->receive($invoice, $data + ['gateway_id' => $gateway->id], $approver);
        $this->fail('Inactive gateway accepted');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
    expect($invoice->payments()->count())->toBe(0);
});
it('carries received payments through to the tax invoice tracker', function () {
    $approver = User::factory()->create(['is_active' => true]);
    $approver->assignRole('Director');
    $invoice = app(InvoiceService::class)->send($this->invoice);
    app(PaymentService::class)->receive($invoice, ['amount' => '100.00', 'currency' => 'USD', 'bank_account_id' => $invoice->bank_account_id, 'txn_ref' => 'audit', 'received_on' => today()->toDateString()], $approver);
    $tax = app(InvoiceService::class)->orderTax($invoice, today()->toDateString(), $this->admin);
    expect(bcsub($tax->grand_total, $tax->receivedAmount(), 2))->toBe('0.00')->and($tax->receivedAmount())->toBe('100.00');
});
it('rejects an open draft edit after another action finalises it', function () {
    $old = Invoice::find($this->invoice->id);
    app(InvoiceService::class)->send($this->invoice);
    expect(fn () => app(OptimisticUpdate::class)->save($old, ['lock_version' => 0, 'due_date' => today()->addDays(90)]))->toThrow(ValidationException::class);
    expect($old->fresh()->status)->toBe('Sent');
});
it('rejects malformed money input as validation instead of crashing', function () {
    expect(fn () => app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', [['matter_ref' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => '1e3']]))->toThrow(ValidationException::class);
    expect(fn () => app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', [['matter_ref' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => '100', 'qty' => 'abc']]))->toThrow(ValidationException::class);
    expect(fn () => app(InvoiceService::class)->convert($this->invoice, 'EUR', '1e2', today()->toDateString(), 'Manual', $this->admin))->toThrow(ValidationException::class);
});
it('reports duplicate master rows before import instead of overwriting them', function () {
    $book = IOFactory::load(base_path('docs/CRM_Master_Data.xlsx'));
    $sheet = $book->getSheetByName('Clients');
    $row = ['Duplicate client', 'Foreign', 'France', '', '', '', '', '', '', '', 'USD', '', '2345'];
    $sheet->fromArray([$row, $row], null, 'A2');
    $path = tempnam(sys_get_temp_dir(), 'audit').'.xlsx';
    try {
        (new Xlsx($book))->save($path);
        $preview = app(MasterImportService::class)->preview($path);
        expect(implode(' ', array_column($preview['errors'], 'error')))->toContain('Duplicate name');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
it('returns a missing file response for archived invoices whose PDF is unavailable', function () {
    $doc = InvoiceDocument::factory()->create(['uploaded_by' => $this->admin->id]);
    $this->get(route('crm.invoice-document', $doc))->assertNotFound();
});
it('exports reference zeroes and names safely without turning names into formulas', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $export = new RowsExport([]);
    $export->bindValue($sheet->getCell('A1'), '0123');
    $export->bindValue($sheet->getCell('B1'), '=1+2');
    $export->bindValue($sheet->getCell('C1'), '1500.50');
    expect($sheet->getCell('A1')->getValue())->toBe('0123')->and($sheet->getCell('B1')->getDataType())->toBe('s')->and($sheet->getCell('C1')->getDataType())->toBe('n');
});

it('prevents duplicate expense payments and stale edits after payment', function () {
    $expense = Expense::create(['expense_type' => 'vendor', 'expense_group' => 'foreign', 'category' => 'Other', 'invoice_date' => today(), 'amount' => 50, 'currency' => 'USD', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->admin->id, 'status' => 'Unpaid']);
    $old = Expense::find($expense->id);
    $service = app(ExpenseService::class);
    $data = ['paid_on' => today()->toDateString(), 'paid_ref' => 'test-payment'];
    $service->paid($expense, $data, $this->admin);
    expect(fn () => $service->paid($expense, $data, $this->admin))->toThrow(ValidationException::class);
    expect(fn () => app(OptimisticUpdate::class)->save($old, ['lock_version' => 0, 'amount' => 99]))->toThrow(ValidationException::class);
    expect($expense->fresh()->amount)->toBe('50.00');
});
it('prevents a reimbursement being overwritten after completion', function () {
    $expense = Expense::create(['expense_type' => 'owner', 'expense_group' => 'indian', 'category' => 'Travel', 'invoice_date' => today(), 'amount' => 50, 'currency' => 'INR', 'paid_by' => 'cash', 'paid_by_user_id' => $this->admin->id, 'status' => 'Paid', 'reimbursement_status' => 'Pending']);
    $service = app(ExpenseService::class);
    $data = ['reimbursement_status' => 'Reimbursed', 'reimbursed_on' => today()->toDateString(), 'reimbursed_ref' => 'test-reimbursement'];
    $service->reimburse($expense, $data, $this->admin);
    expect(fn () => $service->reimburse($expense, $data, $this->admin))->toThrow(ValidationException::class);
    expect($expense->fresh()->reimbursement_status)->toBe('Reimbursed');
});
it('opens all remaining master and settings creation forms', function () {
    foreach (['ClientResource', 'MatterResource', 'ParalegalResource', 'VendorResource', 'BankAccountResource', 'PaymentGatewayResource', 'PatentOfficeResource'] as $name) {
        $page = 'App\\Filament\\Resources\\'.$name.'\\Pages\\ManageRecords';
        Livewire::test($page)->mountAction('create')->assertActionMounted('create');
    }
});

it('clears an earlier preview when a replacement upload is invalid', function (string $route, string $key) {
    $this->withSession([$key => ['rows' => [['old' => 'preview']]]])->post($route, ['file' => UploadedFile::fake()->create('not-an-invoice.txt', 1, 'text/plain')])->assertSessionHasErrors('file')->assertSessionMissing($key);
})->with([['/crm/word/preview', 'word_preview'], ['/crm/import/preview', 'master_preview']]);
it('handles a Word document with an empty table without a server error', function () {
    $word = new PhpWord;
    $word->addSection()->addTable();
    $path = tempnam(sys_get_temp_dir(), 'audit-word').'.docx';
    try {
        PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);
        $result = app(WordService::class)->parse($path, $this->admin);
        expect($result['errors'])->not->toBeEmpty();
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
