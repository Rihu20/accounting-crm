<?php

use App\Filament\Pages\WordUpload;
use App\Filament\Resources\InvoiceResource\Pages\ManageRecords;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PdfService;
use App\Services\WordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
    $this->client = Client::create(['name' => 'Client from master', 'ref_no' => '1256', 'client_type' => 'Foreign', 'country' => 'USA', 'address' => 'Saved master address', 'default_currency' => 'USD']);
    $this->para = Paralegal::create(['name' => 'Seema']);
});
function newMatterWord(array $rows, bool $splitFee = false, ?array $headers = null): string
{
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('Invoice Format: Combined');
    $section->addText('Paralegal: Seema');
    $section->addText('Date: 29-10-2026');
    $section->addText('Client Ref No: 1256');
    $table = $section->addTable();
    foreach (array_merge([$headers ?? WordService::HEADERS], $rows) as $index => $row) {
        $table->addRow();
        foreach ($row as $column => $value) {
            $cell = $table->addCell(1200);
            if ($splitFee && $index > 0 && $column === 5) {
                $run = $cell->addTextRun();
                $run->addText('Official + Profes');
                $run->addText('sional Fees', ['bold' => true]);
            } else {
                $cell->addText($value);
            }
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'word-new').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    return $path;
}
it('reads the client reference from the master and creates reviewed new matters only at confirmation', function () {
    $path = newMatterWord([
        ['N/A', 'P25125603US', '63/974,938', 'NA', 'USA Provisional Filing', 'Official + Professional Fees', 'NA', '490', ''],
        ['NA', 'P25125604US', '63/974,955', 'N/A', 'USA Provisional Filing', 'Official + Professional Fees', 'N/A', '490', ''],
    ], true);
    try {
        $this->post('/crm/word/preview', ['client_ref' => '1256', 'file' => new UploadedFile($path, 'filled.docx', null, null, true)])->assertRedirect('/admin/word-upload')->assertSessionHas('word_preview.errors', []);
        $this->get('/admin/word-upload')->assertOk()->assertSee('Fee summary')->assertSee('2 matter / application(s)')->assertSee('Fees before GST')->assertSee('980.00');
        expect(Matter::count())->toBe(0)->and(Invoice::count())->toBe(0)->and(session('word_preview.missing_matters'))->toHaveCount(2)->and(session('word_preview.client.name'))->toBe('Client from master')->and(session('word_preview.currency'))->toBe('USD');
        $this->post('/crm/word/create')->assertRedirect('/admin/invoices');
        expect(Matter::count())->toBe(2)->and(Invoice::sole()->grand_total)->toBe('980.00')->and(Invoice::sole()->client->address)->toBe('Saved master address');
        expect(Invoice::sole()->items->pluck('app_no')->all())->toBe(['63/974,938', '63/974,955']);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
it('allows the same full matter reference on multiple rows of one invoice', function () {
    Matter::create(['ref_no' => '1256', 'our_ref' => 'P25125603US', 'client_id' => $this->client->id, 'paralegal_id' => $this->para->id, 'matter_title' => 'USA filing', 'country' => 'USA', 'app_no' => 'APP-A', 'service_type' => 'Professional Fees']);
    $path = newMatterWord([
        ['', 'P25125603US', 'APP-A', '', 'USA filing', 'Official + Professional Fees', '', '490', 'USD'],
        ['', 'P25125603US', 'APP-A', '', 'Further action', 'Official + Professional Fees', '', '25', 'USD'],
    ]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin, null, '1256');
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([])->and($parsed['rows'])->toHaveCount(2)->and($parsed['missing_matters'])->toBe([]);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $parsed['rows'], null, 'combined');
    expect($invoice->grand_total)->toBe('515.00')->and($invoice->items()->count())->toBe(2);
    $pages = app(PdfService::class)->pages($invoice);
    expect($pages[0]['items'])->toHaveCount(2)->and(array_column(array_map(fn ($item) => $item->toArray(), $pages[0]['items']), 'matter_text'))->toBe(['USA filing', 'Further action']);
});
it('keeps separate applications under one shared full matter reference', function () {
    $path = newMatterWord([
        ['', 'P25125603US', 'APP-A', '', 'Filing', 'Official + Professional Fees', '', '490', 'USD'],
        ['', 'P25125603US', 'APP-B', '', 'Filing', 'Official + Professional Fees', '', '490', 'USD'],
    ]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([])->and($parsed['rows'])->toHaveCount(2)->and($parsed['missing_matters'])->toHaveCount(1)->and(Matter::count())->toBe(0);
    app(WordService::class)->createMissingMatters($parsed, $this->admin);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $parsed['rows'], null, 'combined');
    expect(Matter::count())->toBe(1)->and($invoice->items()->count())->toBe(2)->and($invoice->grand_total)->toBe('980.00');
    $pages = app(PdfService::class)->pages($invoice);
    expect($pages[0]['items'])->toHaveCount(2)->and(array_map(fn ($item) => $item->app_no, $pages[0]['items']))->toBe(['APP-A', 'APP-B']);
    file_put_contents(storage_path('app/qa-shared-reference-applications.pdf'), app(PdfService::class)->render($invoice));
});
it('rejects a client reference that conflicts with the Word header', function () {
    $path = newMatterWord([['', 'P25125603US', '', '', 'Filing', 'Official + Professional Fees', '', '490', 'USD']]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin, null, '1248');
    } finally {
        unlink($path);
    }
    expect(implode(' ', array_column($parsed['errors'], 'error')))->toContain('differs from the Word file');
});
it('keeps a paralegal from creating masters or viewing another client from a reference', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('Paralegal');
    $this->para->update(['user_id' => $user->id]);
    $this->actingAs($user);
    $path = newMatterWord([['', 'P25125603US', '', '', 'Filing', 'Official + Professional Fees', '', '490', 'USD']]);
    try {
        $parsed = app(WordService::class)->parse($path, $user, null, '1256');
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->not->toBeEmpty()->and($parsed['missing_matters'])->toBe([])->and(Matter::count())->toBe(0);
    $this->get('/admin/word-upload')->assertForbidden();
});
it('shows client master details after entering only its four digit number', function () {
    Livewire::test(WordUpload::class)->set('clientRef', '1256')->assertSee('Client from master')->assertSee('Saved master address');
});

it('downloads all templates with only client ref and preserves the required matter table', function () {
    foreach (['combined' => 'Combined', 'detailed' => 'Detailed', 'india_gst' => 'India_GST'] as $format => $suffix) {
        $this->get('/crm/word/template?format='.$format)->assertDownload('Paralegal_Fees_'.$suffix.'.docx');
        $zip = new ZipArchive;
        $zip->open(base_path('docs/Paralegal_Fees_'.$suffix.'.docx'));
        $text = strip_tags($zip->getFromName('word/document.xml'));
        $zip->close();
        expect($text)->not->toContain('Client name:')->and($text)->toContain('Client Ref No: ____');
        foreach (WordService::HEADERS as $header) {
            expect($text)->toContain($header);
        }
    }
});

it('carries blank continuation details only from the preceding application', function () {
    $path = newMatterWord([
        ['', 'P25125603US', 'APP-A', 'PCT-A', 'Filing', 'Official + Professional Fees', '', '100', 'USD'],
        ['', '', '', '', '', 'Official + Professional Fees', '', '25', 'USD'],
        ['', 'P25125603US', 'APP-B', 'PCT-B', 'Filing', 'Official + Professional Fees', '', '200', 'USD'],
        ['', '', '', '', '', 'Official + Professional Fees', '', '35', 'USD'],
    ]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([])->and(array_column($parsed['rows'], 'app_no'))->toBe(['APP-A', 'APP-A', 'APP-B', 'APP-B'])->and(array_column($parsed['rows'], 'pct_no'))->toBe(['PCT-A', 'PCT-A', 'PCT-B', 'PCT-B']);
});
it('accepts different applications under an existing reference without copying its primary PCT', function () {
    $master = Matter::create(['ref_no' => '1256', 'our_ref' => 'P25125603US', 'client_id' => $this->client->id, 'paralegal_id' => $this->para->id, 'matter_title' => 'Filing', 'country' => 'USA', 'app_no' => 'APP-A', 'pct_no' => 'PCT-A', 'service_type' => 'Professional Fees']);
    $path = newMatterWord([
        ['', 'P25125603US', 'APP-A', 'PCT-A', 'Filing', 'Official + Professional Fees', '', '490', 'USD'],
        ['', 'P25125603US', 'APP-B', 'NA', 'Filing', '', '', '490', 'USD'],
    ]);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([]);
    $invoice = app(InvoiceService::class)->draft($this->admin, $this->client->id, 'USD', $parsed['rows'], null, 'combined');
    expect($invoice->items->pluck('app_no')->all())->toBe(['APP-A', 'APP-B'])->and($invoice->items->pluck('pct_no')->all())->toBe(['PCT-A', null])->and($master->fresh()->app_no)->toBe('APP-A')->and($invoice->grand_total)->toBe('980.00');
});

it('creates separate application entries with a repeated ref in the manual invoice form', function () {
    Matter::create(['ref_no' => '1256', 'our_ref' => 'P25125603US', 'client_id' => $this->client->id, 'paralegal_id' => $this->para->id, 'matter_title' => 'Filing', 'country' => 'USA', 'app_no' => 'APP-A', 'service_type' => 'Professional Fees']);
    Livewire::test(ManageRecords::class)->mountAction('create')
        ->set('mountedActions.0.data.client_id', $this->client->id)->set('mountedActions.0.data.invoice_layout', 'combined')->set('mountedActions.0.data.currency', 'USD')
        ->set('mountedActions.0.data.items', [
            ['ref_no' => 'P25125603US', 'app_no' => 'APP-A', 'fee_type' => 'Official + Professional Fees', 'qty' => 1, 'rate' => 490],
            ['ref_no' => 'P25125603US', 'app_no' => 'APP-B', 'fee_type' => 'Official + Professional Fees', 'qty' => 1, 'rate' => 490],
        ])->callMountedAction()->assertHasNoActionErrors();
    expect(Invoice::sole()->items->pluck('app_no')->all())->toBe(['APP-A', 'APP-B'])->and(Invoice::sole()->grand_total)->toBe('980.00');
});

it('accepts header case and nonbreaking spacing introduced by Word editors', function () {
    $headers = array_map(fn ($header) => mb_strtoupper(str_replace(' ', "\u{00A0}  ", $header)), WordService::HEADERS);
    $path = newMatterWord([['DEMO', 'P25125601US', 'APP-A', '', 'Demo filing', 'Official + Professional Fees', '', '100', 'USD']], false, $headers);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
        expect($parsed['errors'])->toBe([])->and($parsed['rows'])->toHaveCount(1);
    } finally {
        unlink($path);
    }
});

it('still rejects unrelated document headers with an actionable message', function () {
    $path = newMatterWord([], false, ['PRINCIPAL', 'AGENT']);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
        expect($parsed['errors'][0]['error'])->toContain('Download the Combined, Detailed or India GST template');
        expect($parsed['rows'])->toBe([]);
    } finally {
        unlink($path);
    }
});
