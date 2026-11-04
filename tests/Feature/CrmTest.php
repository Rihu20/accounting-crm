<?php

namespace Tests\Feature;

use App\Jobs\ExtractVendorInvoice;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Setting;
use App\Models\StorageFolder;
use App\Models\User;
use App\Services\FolderService;
use App\Services\InvoiceService;
use App\Services\MasterImportService;
use App\Services\Money;
use App\Services\PaymentService;
use App\Services\PdfService;
use App\Services\WordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected Paralegal $paralegal;

    protected Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->admin = User::create(['name' => 'Test Admin', 'email' => 'admin@test.invalid', 'password' => 'test-password-123']);
        $this->admin->assignRole('Accountant');
        $this->actingAs($this->admin);
        $this->client = Client::create(['name' => 'Client', 'client_type' => 'Foreign', 'country' => 'France', 'default_currency' => 'USD']);
        $this->paralegal = Paralegal::create(['name' => 'Seema', 'email' => 'seema@test.invalid']);
        $this->matter = Matter::create(['ref_no' => '0123', 'client_id' => $this->client->id, 'paralegal_id' => $this->paralegal->id, 'matter_title' => 'Office Action Response Filing', 'country' => 'Japan', 'service_type' => 'Professional Fees', 'app_no' => '2023-573492', 'pct_no' => 'PCT/EP2022/053856', 'our_ref' => 'P26012301JP']);
    }

    private function draft(array $rows = []): Invoice
    {
        return app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $rows ?: [['ref_no' => '0123', 'fee_type' => 'Official Fees', 'rate' => '1400'], ['ref_no' => '0123', 'fee_type' => 'Disbursement', 'rate' => '20']]);
    }

    public function test_reference_invoice_and_decimal_rounding(): void
    {
        $i = $this->draft();
        $this->assertSame('1420.00', $i->grand_total);
        $this->assertSame('0.00', $i->tax_total);
        $this->assertSame('USD $ One Thousand Four Hundred Twenty Only', $i->amount_in_words);
        $this->assertSame('1.01', Money::mul('1.005', '1'));
        $this->assertSame('0123', $i->items->first()->ref_no);
    }

    public function test_gst_only_on_professional_fees_and_foreign_exemption(): void
    {
        $rows = [['ref_no' => '0123', 'fee_type' => 'Official Fees', 'rate' => '100'], ['ref_no' => '0123', 'fee_type' => 'Professional Fees', 'rate' => '100'], ['ref_no' => '0123', 'fee_type' => 'Translation', 'rate' => '100'], ['ref_no' => '0123', 'fee_type' => 'Disbursement', 'rate' => '20']];
        $foreign = $this->draft($rows);
        $this->assertSame('0.00', $foreign->tax_total);
        $this->client->update(['client_type' => 'Indian', 'state' => 'Uttar Pradesh']);
        $i = $this->draft($rows);
        $this->assertSame('18.00', $i->tax_total);
        $this->assertSame('338.00', $i->grand_total);
        $html = view('pdf.invoice', ['invoice' => $i, 'pages' => app(PdfService::class)->pages($i), 'settings' => Setting::pluck('value', 'key')->all()])->render();
        $this->assertStringContainsString('CGST', $html);
        $this->client->update(['state' => 'Delhi']);
        $i->refresh();
        $i->unsetRelation('client');
        $html = view('pdf.invoice', ['invoice' => $i, 'pages' => app(PdfService::class)->pages($i), 'settings' => Setting::pluck('value', 'key')->all()])->render();
        $this->assertStringContainsString('IGST', $html);
    }

    public function test_partial_payments_and_order_tax_only_after_full_payment(): void
    {
        $i = app(InvoiceService::class)->send($this->draft());
        $approver = User::create(['name' => 'Director', 'email' => 'director@test.invalid', 'password' => 'test-password-123', 'is_active' => true]);
        $approver->assignRole('Director');
        $service = app(PaymentService::class);
        $service->receive($i, ['bank_account_id' => $i->bank_account_id, 'amount' => '420', 'currency' => 'USD', 'txn_ref' => 'first', 'received_on' => '2026-10-27'], $approver);
        $this->assertSame('Partially received', $i->fresh()->status);
        try {
            app(InvoiceService::class)->orderTax($i, '2026-10-27', $this->admin);
            $this->fail('Partial payment allowed tax');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }$service->receive($i, ['bank_account_id' => $i->bank_account_id, 'amount' => '1000', 'currency' => 'USD', 'txn_ref' => 'second', 'received_on' => '2026-10-27'], $approver);
        $tax = app(InvoiceService::class)->orderTax($i, '2026-10-27', $this->admin);
        $this->assertMatchesRegularExpression('/^AIP\d{6}$/', $tax->number);
        $this->assertSame('Converted', $i->fresh()->status);
        $this->assertSame($i->id, $tax->proforma_id);
        $this->assertSame(1, StorageFolder::count());
        app(FolderService::class)->ensureForTaxInvoice($tax);
        $this->assertSame(1, StorageFolder::count());
        Storage::disk('local')->assertExists(StorageFolder::first()->storage_path.'/'.$tax->number.'.pdf');
        $this->assertGreaterThan(0, DB::table('activity_log')->count());
    }

    public function test_admin_cannot_mark_received_without_permission(): void
    {
        $i = app(InvoiceService::class)->send($this->draft());
        $assistant = User::create(['name' => 'Assistant', 'email' => 'assistant@test.invalid', 'password' => 'test-password-123']);
        $assistant->assignRole('Accountant Assistant');
        $this->expectException(HttpException::class);
        app(PaymentService::class)->receive($i, ['amount' => '1420', 'currency' => 'USD', 'txn_ref' => 'bad', 'received_on' => '2026-10-27'], $assistant);
    }

    public function test_currency_conversion_retains_original(): void
    {
        $i = $this->draft();
        $i = app(InvoiceService::class)->convert($i, 'INR', '83.123456', '2026-10-27', 'Manual', $this->admin);
        $this->assertSame('INR', $i->currency);
        $this->assertSame('118035.31', $i->grand_total);
        $this->assertSame('1420.00', $i->conversions->first()->source_amount);
        $this->assertSame('USD', $i->conversions->first()->source_currency);
        $this->assertCount(2, $i->conversions->first()->original_items);
    }

    public function test_numbers_are_unique_and_drafts_are_unnumbered(): void
    {
        $a = $this->draft();
        $b = $this->draft();
        $this->assertNull($a->number);
        $a = app(InvoiceService::class)->send($a);
        $b = app(InvoiceService::class)->send($b);
        $this->assertNotEquals($a->number, $b->number);
        $this->assertStringEndsWith('0001', $a->number);
        $this->assertStringEndsWith('0002', $b->number);
    }

    public function test_master_template_skips_examples_and_has_exact_headers(): void
    {
        $p = app(MasterImportService::class)->preview(base_path('docs/CRM_Master_Data.xlsx'));
        $this->assertSame(0, $p['rejected']);
        $this->assertSame(0, $p['new']);
        $this->assertSame(4, $p['skipped']);
    }

    public function test_master_import_preserves_leading_zeros_and_is_idempotent(): void
    {
        $book = IOFactory::load(base_path('docs/CRM_Master_Data.xlsx'));
        $book->getSheetByName('Clients')->setCellValue('A2', 'Imported Client');
        $book->getSheetByName('Clients')->setCellValueExplicit('M2', '0001', DataType::TYPE_STRING);
        $book->getSheetByName('Paralegals')->setCellValue('A2', 'Imported Paralegal');
        $book->getSheetByName('Matters')->setCellValueExplicit('A2', '0001', DataType::TYPE_STRING);
        $book->getSheetByName('Matters')->setCellValue('B2', 'Imported Client');
        $book->getSheetByName('Matters')->setCellValue('C2', 'P26000101JP');
        $book->getSheetByName('Matters')->setCellValue('K2', 'Imported Paralegal');
        $path = tempnam(sys_get_temp_dir(), 'master').'.xlsx';
        (new Xlsx($book))->save($path);
        $s = app(MasterImportService::class);
        $p = $s->preview($path);
        $this->assertSame(0, $p['rejected'], json_encode($p['errors']));
        $s->commit($p);
        $s->commit($s->preview($path));
        $this->assertSame(1, Matter::where('ref_no', '0001')->count());
        unlink($path);
    }

    public function test_word_validates_headers_and_creates_draft_data(): void
    {
        $w = new PhpWord;
        $s = $w->addSection();
        $s->addText('Paralegal: Seema');
        $s->addText('Date: 27.10.26');
        $t = $s->addTable();
        foreach ([WordService::LEGACY_HEADERS, ['0123', 'Professional Fees', 'Office Action', '', '800', 'USD']] as $row) {
            $t->addRow();
            foreach ($row as $c) {
                $t->addCell()->addText($c);
            }
        }$path = tempnam(sys_get_temp_dir(), 'word').'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($path);
        $p = app(WordService::class)->parse($path, $this->admin);
        $this->assertSame([], $p['errors'], json_encode($p));
        $this->assertSame('0123', $p['rows'][0]['ref_no']);
        $this->assertSame($this->client->id, $p['client_id']);
        unlink($path);
    }

    public function test_pdf_pagination_footer_and_carry_forward(): void
    {
        foreach ([1, 8, 25, 60] as $count) {
            $rows = array_fill(0, $count, ['ref_no' => '0123', 'fee_type' => 'Professional Fees', 'rate' => '10']);
            $i = $this->draft($rows);
            $pages = app(PdfService::class)->pages($i);
            $this->assertSame(1, count(array_filter($pages, fn ($p) => $p['last'])));
            $this->assertTrue(end($pages)['last']);
            if (count($pages) > 1) {
                $this->assertSame($pages[0]['carried'], $pages[1]['brought']);
            }$pdf = app(PdfService::class)->render($i);
            $this->assertStringStartsWith('%PDF-', $pdf);
            file_put_contents(storage_path('app/qa-'.$count.'.pdf'), $pdf);
        }
    }

    public function test_portal_data_and_download_access_are_restricted(): void
    {
        $u = User::create(['name' => 'Other para', 'email' => 'other@test.invalid', 'password' => 'test-password-123']);
        $u->assignRole('Paralegal');
        $i = $this->draft();
        $this->actingAs($u);
        // Paralegals may read every invoice, but never masters or templates.
        $this->get('/crm/invoices/'.$i->id.'/pdf')->assertOk();
        $this->get('/crm/master-template')->assertForbidden();
        $this->get('/admin/clients')->assertForbidden();
    }

    public function test_daily_checks_are_idempotent(): void
    {
        $i = app(InvoiceService::class)->send($this->draft());
        $i->update(['sent_at' => now()->subDays(15)]);
        $this->artisan('crm:daily-checks')->assertSuccessful();
        $count = DB::table('notifications')->count();
        $this->artisan('crm:daily-checks')->assertSuccessful();
        $this->assertSame($count, DB::table('notifications')->count());
        $this->assertSame(1, DB::table('reminder_events')->count());
    }

    public function test_vendor_bills_without_a_due_date_are_reminded_on_their_bill_date(): void
    {
        $bill = fn (array $row) => Expense::create($row + ['expense_type' => 'vendor', 'status' => 'Unpaid', 'expense_group' => 'foreign', 'currency' => 'USD', 'amount' => '50', 'category' => 'Law firm', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->admin->id]);
        $dated = $bill(['vendor_invoice_no' => 'WITH-DUE', 'invoice_date' => today()->subDays(40), 'due_date' => today()->addDays(2)]);
        $overdue = $bill(['vendor_invoice_no' => 'NO-DUE-OLD', 'invoice_date' => today()->subDays(40)]);
        $future = $bill(['vendor_invoice_no' => 'NO-DUE-FUTURE', 'invoice_date' => today()->addDays(10)]);

        $this->artisan('crm:daily-checks')->assertSuccessful();

        $reminded = DB::table('reminder_events')->pluck('event_key')->all();
        $this->assertContains('vendor:'.$dated->id.':'.today()->toDateString(), $reminded);
        $this->assertContains('vendor:'.$overdue->id.':'.today()->toDateString(), $reminded);
        $this->assertNotContains('vendor:'.$future->id.':'.today()->toDateString(), $reminded);
    }

    public function test_vendor_extraction_does_not_autosave_expenses(): void
    {
        config(['crm.reader_mode' => 'anthropic', 'crm.anthropic_key' => 'test-only', 'crm.anthropic_model' => 'mock-model']);
        Storage::put('test.pdf', 'PDF');
        $id = DB::table('expense_extractions')->insertGetId(['user_id' => $this->admin->id, 'path' => 'test.pdf', 'status' => 'Queued', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"vendor_name":"Test Vendor","amount":"100.00","currency":"USD"}']]])]);
        (new ExtractVendorInvoice($id))->handle();
        $this->assertSame(0, Expense::count());
        $this->assertSame('Review required', DB::table('expense_extractions')->find($id)->status);
    }

    public function test_admin_pages_boot(): void
    {
        foreach (['/admin', '/admin/clients', '/admin/matters', '/admin/invoices', '/admin/expenses', '/admin/import-masters', '/admin/word-upload', '/admin/documents', '/admin/reports', '/admin/settings', '/admin/users', '/admin/vendor-scan'] as $url) {
            $this->assertSame(200, $this->get($url)->status(), $url);
        }
    }

    public function test_conversion_preserves_unit_precision_for_multiple_quantities(): void
    {
        $i = $this->draft([['ref_no' => '0123', 'fee_type' => 'Professional Fees', 'rate' => '1.00', 'qty' => '3.00']]);
        $i = app(InvoiceService::class)->convert($i, 'INR', '83.123456', '2026-10-27', 'Manual', $this->admin);
        $this->assertSame('249.37', $i->grand_total);
        $this->assertSame('3.00', $i->items->first()->qty);
        $this->assertSame('83.12345600', $i->items->first()->rate);
    }

    public function test_first_run_admin_login_redirects_to_setup(): void
    {
        $this->admin->removeRole('Accountant');
        $this->app['env'] = 'local';
        $this->get('/admin/login')->assertRedirect('/setup');
    }
}
