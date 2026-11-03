<?php

use App\Jobs\ExtractVendorInvoice;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use App\Services\LocalInvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    config(['crm.reader_mode' => 'local', 'crm.anthropic_key' => null, 'crm.anthropic_model' => null]);
    Http::preventStrayRequests();
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('Accountant');
    $this->actingAs($this->admin);
});

it('reads a text PDF immediately without an API or queue worker and saves only on confirmation', function () {
    $file = UploadedFile::fake()->createWithContent('invoice.pdf', file_get_contents(base_path('tests/Fixtures/vendor-text.pdf')));
    $this->post('/crm/extractions', ['file' => $file])->assertRedirect('/admin/vendor-scan');
    $row = DB::table('expense_extractions')->first();
    $data = json_decode($row->data, true);
    expect($row->status)->toBe('Review required')
        ->and($data['invoice_no'])->toBe('TEST-2601')
        ->and($data['amount'])->toBe('130000.00')
        ->and($data['currency'])->toBe('JPY')
        ->and(Expense::count())->toBe(0)->and(Vendor::count())->toBe(0);
    $this->get('/admin/vendor-scan')->assertOk()->assertSee('130000.00')->assertSee('no API key required');
    $this->post('/crm/extractions/'.$row->id.'/confirm', ['vendor_name' => $data['vendor_name'], 'vendor_country' => 'Japan', 'vendor_invoice_no' => $data['invoice_no'], 'invoice_date' => $data['invoice_date'], 'currency' => 'JPY', 'amount' => $data['amount']])->assertRedirect('/admin/expenses');
    expect(Expense::first()->currency)->toBe('JPY')->and(Vendor::count())->toBe(1);
    $this->post('/crm/extractions/'.$row->id.'/retry')->assertStatus(422);
});

it('reads image-only PDFs with local Windows OCR', function () {
    $result = app(LocalInvoiceReader::class)->read(base_path('tests/Fixtures/vendor-scanned.pdf'));
    expect($result['reader'])->toBe('local-ocr')
        ->and($result['data']['invoice_no'])->toBe('TEST-2601')
        ->and($result['data']['amount'])->toBe('130000.00')
        ->and($result['data']['currency'])->toBe('JPY');
});

it('allows retrying a failed upload but blocks another users upload', function () {
    Storage::put('invoice.pdf', file_get_contents(base_path('tests/Fixtures/vendor-text.pdf')));
    $id = DB::table('expense_extractions')->insertGetId(['user_id' => $this->admin->id, 'path' => 'invoice.pdf', 'status' => 'Manual review', 'error' => 'AI not configured', 'created_at' => now(), 'updated_at' => now()]);
    $this->post('/crm/extractions/'.$id.'/retry')->assertRedirect('/admin/vendor-scan');
    expect(DB::table('expense_extractions')->find($id)->status)->toBe('Review required')->and(Expense::count())->toBe(0);
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole('Accountant');
    $this->actingAs($other)->post('/crm/extractions/'.$id.'/retry')->assertNotFound();
});

it('keeps damaged PDFs available for manual review', function () {
    Storage::put('bad.pdf', 'not a valid PDF');
    $id = DB::table('expense_extractions')->insertGetId(['user_id' => $this->admin->id, 'path' => 'bad.pdf', 'status' => 'Queued', 'created_at' => now(), 'updated_at' => now()]);
    (new ExtractVendorInvoice($id))->handle();
    expect(DB::table('expense_extractions')->find($id)->status)->toBe('Manual review')->and(Expense::count())->toBe(0);
});
