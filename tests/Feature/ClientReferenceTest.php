<?php

use App\Filament\Resources\MatterResource\Pages\ManageRecords;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\StorageFolder;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MasterImportService;
use App\Services\MatterReference;
use App\Services\WordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('Accountant');
    $this->actingAs($this->admin);
    $this->client = Client::create(['name' => 'Reference Client', 'ref_no' => '1248', 'client_type' => 'Foreign', 'country' => 'France', 'default_currency' => 'USD']);
    $this->para = Paralegal::create(['name' => 'Reference Paralegal']);
});
it('decodes the users reference format without losing leading zeroes', function () {
    expect(MatterReference::parse('P25124809KR'))->toBe(['type' => 'Patent', 'year' => 2025, 'client_ref' => '1248', 'matter_no' => '09', 'country_code' => 'KR']);
    expect(MatterReference::parse('D26002301IN')['client_ref'])->toBe('0023')->and(MatterReference::parse('T26002302US')['type'])->toBe('Trademark');
});
it('imports multiple matters for one client reference and remains idempotent', function () {
    $book = IOFactory::load(base_path('docs/CRM_Master_Data.xlsx'));
    $book->getSheetByName('Clients')->setCellValue('A2', 'Imported Reference Client')->setCellValueExplicit('M2', '0023', DataType::TYPE_STRING);
    $book->getSheetByName('Paralegals')->setCellValue('A2', 'Imported Reference Paralegal');
    $sheet = $book->getSheetByName('Matters');
    foreach (['P26002301KR', 'D26002302KR'] as $i => $ref) {
        $sheet->fromArray(['0023', 'Imported Reference Client', $ref, '', 'Matter '.($i + 1), 'South Korea', '', '', 'Professional Fees', '', 'Imported Reference Paralegal', 'Active'], null, 'A'.($i + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'refs').'.xlsx';
    (new Xlsx($book))->save($path);
    try {
        $s = app(MasterImportService::class);
        $preview = $s->preview($path);
        expect($preview['errors'])->toBe([]);
        $s->commit($preview);
        $s->commit($s->preview($path));
        expect(Client::where('name', 'Imported Reference Client')->first()->ref_no)->toBe('0023')->and(Matter::where('ref_no', '0023')->count())->toBe(2);
        $sheet->setCellValue('A2', '9999');
        (new Xlsx($book))->save($path);
        expect($s->preview($path)['rejected'])->toBeGreaterThan(0);
    } finally {
        unlink($path);
    }
});
it('resolves full references and rejects ambiguous four digit lookups', function () {
    foreach (['P25124809KR', 'T25124810KR'] as $ref) {
        Matter::create(['ref_no' => '1248', 'our_ref' => $ref, 'client_id' => $this->client->id, 'paralegal_id' => $this->para->id, 'matter_title' => $ref, 'country' => 'South Korea', 'service_type' => 'Professional Fees']);
    }
    expect(MatterReference::resolve('T25124810KR')->our_ref)->toBe('T25124810KR');
    expect(fn () => MatterReference::resolve('1248'))->toThrow(ValidationException::class);
    expect(fn () => Matter::create(['ref_no' => '9999', 'our_ref' => 'P25124811KR', 'client_id' => $this->client->id, 'paralegal_id' => $this->para->id, 'matter_title' => 'Wrong ref', 'country' => 'South Korea', 'service_type' => 'Professional Fees']))->toThrow(ValidationException::class);
});
it('reads full matter references from Word and keeps invoice and document links separate', function () {
    $refs = ['P25124809KR', 'D25124810KR'];
    foreach ($refs as $ref) {
        Matter::create(['ref_no' => '1248', 'our_ref' => $ref, 'client_id' => $this->client->id, 'paralegal_id' => $this->para->id, 'matter_title' => $ref, 'country' => 'South Korea', 'service_type' => 'Professional Fees']);
    }
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('Paralegal: '.$this->para->name);
    $section->addText('Date: 27.10.2026');
    $table = $section->addTable();
    foreach ([WordService::LEGACY_HEADERS, [$refs[0], 'Professional Fees', 'First matter', '', '100', 'USD'], [$refs[1], 'Professional Fees', 'Second matter', '', '200', 'USD']] as $row) {
        $table->addRow();
        foreach ($row as $value) {
            $table->addCell(1500)->addText($value);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'word').'.docx';
    PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);
    try {
        $parsed = app(WordService::class)->parse($path, $this->admin);
    } finally {
        unlink($path);
    }
    expect($parsed['errors'])->toBe([])->and(array_column($parsed['rows'], 'matter_ref'))->toBe($refs);
    $service = app(InvoiceService::class);
    $invoice = $service->draft($this->admin, $this->client->id, 'USD', $parsed['rows']);
    expect($invoice->items->pluck('matter_id')->unique()->count())->toBe(2)->and($invoice->items->pluck('ref_no')->unique()->all())->toBe(['1248']);
    $invoice = $service->send($invoice);
    $invoice->update(['status' => 'Received']);
    $service->orderTax($invoice, today()->toDateString(), $this->admin);
    expect(StorageFolder::count())->toBe(2)->and(StorageFolder::pluck('matter_id')->unique()->count())->toBe(2);
});
it('keeps the old master workbook header layout importable', function () {
    $book = IOFactory::load(base_path('docs/CRM_Master_Data.xlsx'));
    $book->getSheetByName('Clients')->removeColumn('M');
    $path = tempnam(sys_get_temp_dir(), 'legacy').'.xlsx';
    (new Xlsx($book))->save($path);
    try {
        expect(app(MasterImportService::class)->preview($path)['rejected'])->toBe(0);
    } finally {
        unlink($path);
    }
});
it('fills client mapping and reference details in the CRM matter form', function () {
    Livewire::test(ManageRecords::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.our_ref', 't25124810kr')
        ->assertSet('mountedActions.0.data.our_ref', 'T25124810KR')
        ->assertSet('mountedActions.0.data.ref_no', '1248')
        ->assertSet('mountedActions.0.data.client_id', $this->client->id)
        ->set('mountedActions.0.data.paralegal_id', $this->para->id)
        ->set('mountedActions.0.data.matter_title', 'Trademark filing')
        ->set('mountedActions.0.data.country', 'South Korea')
        ->set('mountedActions.0.data.service_type', 'Professional Fees')
        ->callMountedAction()
        ->assertHasNoActionErrors();
    expect(Matter::where('our_ref', 'T25124810KR')->first()->client_id)->toBe($this->client->id);
});
it('serves the revised master and Word formats from CRM download buttons', function () {
    $this->get('/admin/import-masters')->assertOk()->assertSee('Download updated master workbook')->assertSee('column M');
    $this->get('/admin/word-upload')->assertOk()->assertSee('Download updated Word template')->assertSee('P25124809KR');
    $this->get('/crm/master-template')->assertDownload('CRM_Master_Data.xlsx');
    $this->get('/crm/word/template')->assertDownload('Paralegal_Fee_Template.docx');
    $this->get('/admin/invoices')->assertOk()->assertSee('Matter Ref')->assertSee('Client Ref');
});
