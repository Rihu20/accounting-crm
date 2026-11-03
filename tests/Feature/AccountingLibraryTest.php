<?php

use App\Filament\Resources\InvoiceDocumentResource\Pages\ManageRecords;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Models\PatentOffice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('Accountant');
    $this->actingAs($this->admin);
});
it('archives original PDFs by type and month without creating ledger invoices', function () {
    $pdf = UploadedFile::fake()->createWithContent('April invoice.pdf', "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF");
    $this->post('/crm/invoice-documents', ['files' => [$pdf], 'doc_type' => 'tax', 'period_year' => 2026, 'period_month' => 4])->assertRedirect('/admin/invoice-documents');
    $record = InvoiceDocument::sole();
    expect($record->file_name)->toBe('April invoice.pdf')->and($record->period_month)->toBe(4)->and(Invoice::count())->toBe(0);
    $this->get(route('crm.invoice-document', $record))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Storage::disk('local')->assertExists($record->path);
});
it('rejects duplicates and another users upload path', function () {
    $path = 'InvoiceUploads/'.$this->admin->id.'/test.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 original');
    $service = app(AccountingFileService::class);
    $service->archive([$path], ['doc_type' => 'proforma'], $this->admin);
    expect(fn () => $service->archive([$path], ['doc_type' => 'tax'], $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $service->archive(['InvoiceUploads/9999/test.pdf'], ['doc_type' => 'tax'], $this->admin))->toThrow(ValidationException::class);
    expect(InvoiceDocument::count())->toBe(1);
});
it('opens the invoice and expense admin sections', function () {
    foreach (['/admin/invoices', '/admin/invoice-documents', '/admin/expenses', '/admin/patent-offices'] as $url) {
        $this->get($url)->assertOk();
    }
});
it('restricts PDF access for staff without invoice permission and expenses belonging to others', function () {
    $path = 'InvoiceUploads/'.$this->admin->id.'/test.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 original');
    $record = app(AccountingFileService::class)->archive([$path], ['doc_type' => 'tax'], $this->admin)[0];
    $expense = Expense::create(['expense_type' => 'owner', 'expense_group' => 'indian', 'category' => 'Admin expense', 'invoice_date' => today(), 'amount' => 500, 'currency' => 'INR', 'paid_by' => 'owner_card', 'paid_by_user_id' => $this->admin->id, 'status' => 'Paid', 'bill_path' => 'Expenses/test.pdf']);
    Storage::disk('local')->put('Expenses/test.pdf', '%PDF-1.4 receipt');
    $paralegal = User::factory()->create(['is_active' => true]);
    $paralegal->assignRole('Paralegal');
    $this->actingAs($paralegal);
    $this->get(route('crm.invoice-document', $record))->assertForbidden();
    // Indian admin expenses are not part of the paralegal view (law firms + patent offices only).
    $this->get(route('crm.expense-file', $expense))->assertForbidden();
    $director = User::factory()->create(['is_active' => true]);
    $director->assignRole('Director');
    $this->actingAs($director);
    $this->get(route('crm.expense-file', $expense))->assertOk();
});
it('keeps admin expense under Indian and validates patent office selection', function () {
    $base = ['expense_type' => 'vendor', 'invoice_date' => today(), 'amount' => 100, 'currency' => 'USD', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->admin->id, 'category' => 'Other', 'status' => 'Unpaid'];
    $vendor = Vendor::create(['name' => 'Foreign Law firm', 'country' => 'France', 'vendor_type' => 'Foreign']);
    $foreign = Expense::create($base + ['vendor_id' => $vendor->id]);
    expect($foreign->expense_group)->toBe('foreign');
    $indian = Expense::create(array_replace($base, ['expense_group' => 'indian', 'category' => 'Admin expense']));
    expect($indian->expense_group)->toBe('indian');
    expect(fn () => Expense::create($base + ['expense_group' => 'patent_office']))->toThrow(ValidationException::class);
    $office = PatentOffice::where('acronym', 'USPTO')->firstOrFail();
    $official = Expense::create($base + ['expense_group' => 'patent_office', 'patent_office_id' => $office->id]);
    expect($official->payee_name)->toBe($office->name)->and($official->patentOffice->id)->toBe($office->id);
});

it('opens upload and expense forms with their required fields', function () {
    Livewire::test(ManageRecords::class)
        ->mountAction('upload_pdfs')->assertActionMounted('upload_pdfs')->assertSet('mountedActions.0.data.file_names', []);
    Livewire::test(App\Filament\Resources\ExpenseResource\Pages\ManageRecords::class)
        ->mountAction('create')->assertActionMounted('create')->assertSet('mountedActions.0.data.expense_group','indian')->assertSet('mountedActions.0.data.expense_type','vendor');
});
