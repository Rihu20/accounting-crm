<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PdfService;
use App\Services\WordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('Accountant');
    $this->actingAs($this->admin);
    $this->client = Client::create(['name' => 'Format Test Client', 'ref_no' => '1248', 'client_type' => 'Foreign', 'country' => 'France', 'default_currency' => 'USD']);
    $para = Paralegal::create(['name' => 'Test Paralegal']);
    Matter::create(['ref_no' => '1248', 'our_ref' => 'P25124809KR', 'client_id' => $this->client->id, 'paralegal_id' => $para->id, 'matter_title' => 'Response filing', 'country' => 'South Korea', 'app_no' => '10-2025-000001', 'pct_no' => 'PCT/XX2024/000001', 'service_type' => 'Professional Fees']);
});
it('reads the actual three Word templates into the correct invoice layout', function (string $mode, string $total, string $tax) {
    if ($mode === 'india_gst') {
        $this->client->update(['client_type' => 'Indian', 'country' => 'India', 'state' => 'Uttar Pradesh', 'default_currency' => 'INR']);
    }
    $parsed = app(WordService::class)->parse(base_path('tests/Fixtures/word-'.$mode.'.docx'), $this->admin);
    expect($parsed['errors'])->toBe([])->and($parsed['invoice_layout'])->toBe($mode);
    expect(array_unique(array_column($parsed['rows'], 'matter_ref')))->toBe(['P25124809KR']);
    $invoice = app(InvoiceService::class)->draft($this->admin, $parsed['client_id'], $parsed['currency'], $parsed['rows'], null, $parsed['invoice_layout']);
    expect($invoice->grand_total)->toBe($total)->and($invoice->tax_total)->toBe($tax)->and($invoice->invoice_layout)->toBe($mode);
    $pdfService = app(PdfService::class);
    $pages = $pdfService->pages($invoice);
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => $pages, 'settings' => Setting::pluck('value', 'key')->all()])->render();
    expect(substr_count($html, 'Our ref: P25124809KR'))->toBe(1);
    if ($mode === 'combined') {
        expect($pages[0]['items'])->toHaveCount(1);
        expect($html)->toContain('Official + Professional Fees')->not->toContain('>Official Fees<');
    }
    if ($mode === 'detailed') {
        expect($html)->toContain('Official Fees', 'Professional Fees', 'Translation', 'Spanish');
    }
    if ($mode === 'india_gst') {
        expect($html)->toContain('GST taxable Professional Fees', 'CGST', 'SGST');
    }
    file_put_contents(storage_path('app/qa-format-'.$mode.'.pdf'), $pdfService->render($invoice));
})->with([['combined', '1500.00', '0.00'], ['detailed', '1700.00', '0.00'], ['india_gst', '1590.00', '90.00']]);
it('combines stored official and professional fees without losing their internal breakdown', function () {
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', [['matter_ref' => 'P25124809KR', 'fee_type' => 'Official Fees', 'rate' => '1000'], ['matter_ref' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => '500']], null, 'combined');
    $pages = app(PdfService::class)->pages($invoice);
    expect($invoice->items()->count())->toBe(2)->and($pages[0]['items'])->toHaveCount(1)->and($pages[0]['items'][0]->amount)->toBe('1500.00');
});
it('rejects a contradictory format and combined charges when GST needs a professional fee base', function () {
    $parsed = app(WordService::class)->parse(base_path('tests/Fixtures/word-combined.docx'), $this->admin, 'detailed');
    expect($parsed['errors'])->not->toBe([]);
    $this->client->update(['client_type' => 'Indian', 'state' => 'Delhi']);
    expect(fn () => app(InvoiceService::class)->draft($this->admin, $this->client->id, 'INR', [['matter_ref' => 'P25124809KR', 'fee_type' => 'Official + Professional Fees', 'rate' => '1500']], null, 'india_gst'))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0);
});
it('taxes professional fees only even if another fee is incorrectly marked taxable', function () {
    $this->client->update(['client_type' => 'Indian', 'state' => 'Delhi']);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'INR', [['matter_ref' => 'P25124809KR', 'fee_type' => 'Official Fees', 'rate' => '1000', 'taxable' => true], ['matter_ref' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => '500', 'taxable' => false]], null, 'india_gst');
    expect($invoice->tax_total)->toBe('90.00')->and($invoice->grand_total)->toBe('1590.00');
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => app(PdfService::class)->pages($invoice), 'settings' => Setting::pluck('value', 'key')->all()])->render();
    expect($html)->toContain('IGST')->not->toContain('CGST');
});
it('keeps the chosen layout when creating a tax invoice and exposes all three downloads', function () {
    $parsed = app(WordService::class)->parse(base_path('tests/Fixtures/word-combined.docx'), $this->admin);
    $service = app(InvoiceService::class);
    $invoice = $service->draft($this->admin, $this->client->id, 'USD', $parsed['rows'], null, 'combined');
    $invoice = $service->send($invoice);
    $invoice->update(['status' => 'Received']);
    $tax = $service->orderTax($invoice, today()->toDateString(), $this->admin);
    expect($tax->invoice_layout)->toBe('combined');
    foreach (['combined' => 'Combined', 'detailed' => 'Detailed', 'india_gst' => 'India_GST'] as $mode => $suffix) {
        $this->get('/crm/word/template?format='.$mode)->assertDownload('Paralegal_Fees_'.$suffix.'.docx');
    }
    $this->get('/admin/word-upload')->assertOk()->assertSee('Download Combined Word')->assertSee('Download Detailed Word')->assertSee('Download India GST Word');
});
it('prints the stored professional GST rate even after the default setting changes', function () {
    $this->client->update(['client_type' => 'Indian', 'state' => 'Uttar Pradesh']);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'INR', [['matter_ref' => 'P25124809KR', 'fee_type' => 'Official Fees', 'rate' => '1000'], ['matter_ref' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => '500']], null, 'india_gst');
    Setting::where('key', 'gst_rate')->update(['value' => '12']);
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => app(PdfService::class)->pages($invoice), 'settings' => Setting::pluck('value', 'key')->all()])->render();
    expect($html)->toContain('CGST 9.00%', 'SGST 9.00%')->not->toContain('CGST 6.00%');
});
function matterDetailsWord(array $rows): string
{
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('Paralegal: Test Paralegal');
    $section->addText('Date: 27.10.2026');
    $section->addText('Invoice Format: Detailed');
    $table = $section->addTable();
    foreach (array_merge([WordService::HEADERS], $rows) as $row) {
        $table->addRow();
        foreach ($row as $value) {
            $table->addCell(1300)->addText($value);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'matter-details').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    return $path;
}
it('keeps each matters own identifiers and carries them only to that matters fee rows', function () {
    $first = Matter::where('our_ref', 'P25124809KR')->first();
    $first->update(['your_ref' => 'CLIENT-A']);
    $second = Matter::create(['ref_no' => '1248', 'our_ref' => 'D25124810KR', 'client_id' => $this->client->id, 'paralegal_id' => $first->paralegal_id, 'matter_title' => 'Design filing', 'country' => 'South Korea', 'app_no' => '30-2025-000002', 'pct_no' => 'PCT/XX2024/000002', 'your_ref' => 'CLIENT-B', 'service_type' => 'Professional Fees']);
    $path = matterDetailsWord([
        ['CLIENT-A', 'P25124809KR', '10-2025-000001', 'PCT/XX2024/000001', 'Response filing', 'Official Fees', '', '1000', 'USD'],
        ['', '', '', '', '', 'Professional Fees', '', '500', 'USD'],
        ['CLIENT-B', 'D25124810KR', '30-2025-000002', 'PCT/XX2024/000002', 'Design filing', 'Official Fees', '', '200', 'USD'],
        ['', '', '', '', '', 'Professional Fees', '', '100', 'USD'],
    ]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([])->and(array_column($parsed['rows'], 'app_no'))->toBe(['10-2025-000001', '10-2025-000001', '30-2025-000002', '30-2025-000002'])->and(array_column($parsed['rows'], 'your_ref'))->toBe(['CLIENT-A', 'CLIENT-A', 'CLIENT-B', 'CLIENT-B']);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $parsed['rows'], null, 'detailed');
    expect($invoice->items->pluck('pct_no')->all())->toBe(['PCT/XX2024/000001', 'PCT/XX2024/000001', 'PCT/XX2024/000002', 'PCT/XX2024/000002']);
    expect($invoice->items->pluck('our_ref')->unique()->count())->toBe(2)->and($invoice->items->pluck('ref_no')->unique()->all())->toBe(['1248']);
});
it('accepts a separate application under the same parent matter reference', function () {
    $path = matterDetailsWord([['', 'P25124809KR', 'SECOND-APPLICATION', 'PCT/XX2024/000002', 'Response filing', 'Professional Fees', '', '500', 'USD']]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([])->and($parsed['rows'][0]['app_no'])->toBe('SECOND-APPLICATION')->and($parsed['rows'][0]['pct_no'])->toBe('PCT/XX2024/000002');
    expect(Matter::where('our_ref', 'P25124809KR')->first()->app_no)->toBe('10-2025-000001');
});
it('retains supplied identifiers in the invoice when the master identifiers are empty', function () {
    Matter::where('our_ref', 'P25124809KR')->first()->update(['app_no' => null, 'pct_no' => null]);
    $path = matterDetailsWord([['CUSTOMER-REF', 'P25124809KR', 'APP-2025-NEW', 'PCT/XX2024/000009', 'Response filing', 'Professional Fees', '', '500', 'USD']]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([]);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $parsed['rows'], null, 'detailed');
    expect($invoice->items->first()->app_no)->toBe('APP-2025-NEW')->and($invoice->items->first()->pct_no)->toBe('PCT/XX2024/000009')->and($invoice->items->first()->your_ref)->toBe('CUSTOMER-REF');
});
