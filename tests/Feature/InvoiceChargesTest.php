<?php

use App\Filament\Resources\InvoiceResource\Pages\ManageRecords;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->client = Client::create(['name' => 'Charges test', 'ref_no' => '1248', 'client_type' => 'Foreign', 'country' => 'France', 'default_currency' => 'USD']);
    $para = Paralegal::create(['name' => 'Test Paralegal']);
    Matter::create(['ref_no' => '1248', 'our_ref' => 'P25124809KR', 'client_id' => $this->client->id, 'paralegal_id' => $para->id, 'matter_title' => 'Response filing', 'country' => 'South Korea', 'service_type' => 'Professional Fees']);
    $this->rows = [['matter_ref' => 'P25124809KR', 'fee_type' => 'Official Fees', 'rate' => '1000'], ['matter_ref' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => '500']];
    $this->charges = [['label' => 'Platform Fees', 'amount' => '10'], ['label' => 'Merchant Charges', 'amount' => '15.50']];
});
it('adds named charges once and keeps GST on professional fees only', function () {
    $this->client->update(['client_type' => 'Indian', 'state' => 'Delhi']);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'INR', $this->rows, null, 'india_gst', $this->charges);
    expect($invoice->subtotal)->toBe('1500.00')->and($invoice->tax_total)->toBe('90.00')->and($invoice->additional_charges_total)->toBe('25.50')->and($invoice->grand_total)->toBe('1615.50');
    $invoice = app(InvoiceService::class)->recalculate($invoice);
    expect($invoice->grand_total)->toBe('1615.50');
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => app(PdfService::class)->pages($invoice), 'settings' => []])->render();
    expect($html)->toContain('Platform Fees', 'Merchant Charges', '15.50')->not->toContain('>Disbursement<');
});
it('keeps legacy amounts and allows their printed label to change', function () {
    $rows = $this->rows;
    $rows[] = ['matter_ref' => 'P25124809KR', 'fee_type' => 'Disbursement', 'rate' => '20'];
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $rows);
    $invoice->update(['disbursement_label' => 'Payment Gateway Charges']);
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => app(PdfService::class)->pages($invoice), 'settings' => []])->render();
    expect($invoice->grand_total)->toBe('1520.00')->and($html)->toContain('Payment Gateway Charges')->not->toContain('>Disbursement<');
});
it('converts extra charges and preserves them on the tax invoice', function () {
    $service = app(InvoiceService::class);
    $invoice = $service->draft($this->admin, $this->client->id, 'USD', $this->rows, null, 'detailed', $this->charges);
    $invoice = $service->convert($invoice, 'EUR', '2', today()->toDateString(), 'Manual', $this->admin);
    expect($invoice->additional_charges_total)->toBe('51.00')->and($invoice->grand_total)->toBe('3051.00')->and($invoice->conversions->first()->original_charges[1]['amount'])->toBe('15.50');
    $invoice->update(['status' => 'Received']);
    $tax = $service->orderTax($invoice, today()->toDateString(), $this->admin);
    expect($tax->additional_charges)->toBe($invoice->additional_charges)->and($tax->grand_total)->toBe('3051.00');
});
it('rejects invalid extra charges without creating an invoice', function () {
    expect(fn () => app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $this->rows, null, 'detailed', [['label' => 'Merchant Charges', 'amount' => '-5']]))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0);
});
it('creates a draft with custom charges from the CRM form', function () {
    Livewire::test(ManageRecords::class)->mountAction('create')
        ->set('mountedActions.0.data.client_id', $this->client->id)->set('mountedActions.0.data.invoice_layout', 'detailed')->set('mountedActions.0.data.currency', 'USD')
        ->set('mountedActions.0.data.items', [['ref_no' => 'P25124809KR', 'fee_type' => 'Professional Fees', 'rate' => 500, 'qty' => 1]])
        ->set('mountedActions.0.data.additional_charges', $this->charges)->callMountedAction()->assertHasNoActionErrors();
    expect(Invoice::sole()->grand_total)->toBe('525.50');
});
it('paginates a long list of custom charges and escapes charge labels', function () {
    $charges = array_fill(0, 10, ['label' => substr(str_repeat('Platform Fees <script>alert(1)</script> ', 3), 0, 80), 'amount' => '1.25']);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $this->rows, null, 'detailed', $charges);
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => app(PdfService::class)->pages($invoice), 'settings' => []])->render();
    expect($html)->not->toContain('<script>')->and($invoice->grand_total)->toBe('1512.50');
    $pages = app(PdfService::class)->pages($invoice);
    $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'pages' => $pages, 'settings' => []])->setPaper('a4');
    $pdf->render();
    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(count($pages));
    file_put_contents(storage_path('app/qa-custom-charges.pdf'), $pdf->output());
});
