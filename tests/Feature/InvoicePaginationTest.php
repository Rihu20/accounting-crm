<?php

use App\Models\Client;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('Accountant');
    $this->actingAs($this->admin);
    $this->client = Client::create(['name' => 'Pagination client', 'ref_no' => '1104', 'client_type' => 'Foreign', 'country' => 'USA', 'address' => 'A billing address', 'default_currency' => 'USD']);
    $this->para = Paralegal::create(['name' => 'Seema']);
});

it('fills available space and carries every matter and amount exactly once', function (string $layout) {
    if ($layout === 'india_gst') {
        $this->client->update(['client_type' => 'Indian', 'country' => 'India', 'state' => 'Uttar Pradesh', 'default_currency' => 'INR']);
    }
    $rows = [];
    for ($i = 1; $i <= 10; $i++) {
        $reference = sprintf('P261104%02dUS', $i);
        Matter::create(['client_id' => $this->client->id, 'paralegal_id' => $this->para->id, 'our_ref' => $reference, 'matter_title' => 'National phase patent filing '.$i, 'country' => 'USA', 'app_no' => '18/90100'.$i, 'pct_no' => 'PCT/US2026/00110'.$i, 'your_ref' => 'DEMO-1104-'.$i, 'service_type' => 'Professional Fees']);
        foreach ($layout === 'combined' ? ['Official + Professional Fees'] : ['Official Fees', 'Professional Fees', 'Translation'] as $fee) {
            $rows[] = ['matter_ref' => $reference, 'fee_type' => $fee, 'rate' => '100', 'language' => $fee === 'Translation' ? 'French' : null];
        }
    }
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, $layout === 'india_gst' ? 'INR' : 'USD', $rows, null, $layout);
    $pages = app(PdfService::class)->pages($invoice);
    expect(count($pages))->toBeLessThan(5);
    if ($layout === 'combined') {
        expect(count($pages[0]['items']))->toBeGreaterThanOrEqual(4);
    }
    $printed = collect($pages)->flatMap(fn ($page) => $page['items']);
    expect($printed->count())->toBe(count($rows))
        ->and($printed->pluck('id')->unique()->count())->toBe(count($rows))
        ->and(end($pages)['carried'])->toBe($invoice->subtotal)
        ->and(count(array_filter($pages, fn ($page) => $page['last'])))->toBe(1);
    foreach ($pages as $index => $page) {
        expect($page['first'])->toBe($index === 0);
        if ($index > 0) {
            expect($page['brought'])->toBe($pages[$index - 1]['carried']);
        }
        if ($page['items']) {
            expect($page['items'][0]->show_matter)->toBeTrue();
        }
    }
    $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'pages' => $pages, 'settings' => Setting::pluck('value', 'key')->all()])->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    $pdf->render();
    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(count($pages));
})->with(['combined', 'detailed', 'india_gst']);
