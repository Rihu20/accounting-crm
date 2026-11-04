<?php

use App\Models\Client;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Vendor;
use App\Services\MasterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->archivedClient = Client::create(['ref_no' => '0777', 'name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    $this->archivedClient->delete();
});

it('names the archived record instead of failing on the unique index', function () {
    expect(fn () => Client::create(['name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']))
        ->toThrow(ValidationException::class);

    try {
        Client::create(['name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    } catch (ValidationException $exception) {
        expect($exception->errors()['name'][0])
            ->toContain('archived client (#'.$this->archivedClient->id.')')
            ->toContain('crm:archived-masters --restore=Client:'.$this->archivedClient->id);
    }

    expect(Client::withTrashed()->where('name', 'Acme GmbH')->count())->toBe(1);
});

it('guards the client reference as well as the name', function () {
    expect(fn () => Client::create(['ref_no' => '0777', 'name' => 'Another GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']))
        ->toThrow(ValidationException::class);
});

it('guards archived vendors, matters and paralegals', function () {
    $vendor = Vendor::create(['name' => 'Overseas Counsel', 'vendor_type' => 'Foreign', 'country' => 'USA', 'default_currency' => 'USD']);
    $vendor->delete();
    expect(fn () => Vendor::create(['name' => 'Overseas Counsel', 'vendor_type' => 'Foreign', 'country' => 'USA', 'default_currency' => 'USD']))
        ->toThrow(ValidationException::class);

    $paralegal = Paralegal::create(['name' => 'Seema', 'email' => 'seema@firm.test']);
    $paralegal->delete();
    expect(fn () => Paralegal::create(['name' => 'Someone else', 'email' => 'seema@firm.test']))
        ->toThrow(ValidationException::class);

    $live = Client::create(['ref_no' => '0888', 'name' => 'Live Client', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    $keeper = Paralegal::create(['name' => 'Keeper']);
    $matter = Matter::create(['client_id' => $live->id, 'paralegal_id' => $keeper->id, 'ref_no' => '0888', 'our_ref' => 'P26088801IN', 'matter_title' => 'Design', 'country' => 'India', 'service_type' => 'Professional Fees']);
    $matter->delete();
    expect(fn () => Matter::create(['client_id' => $live->id, 'paralegal_id' => $keeper->id, 'ref_no' => '0888', 'our_ref' => 'P26088801IN', 'matter_title' => 'Again', 'country' => 'India', 'service_type' => 'Professional Fees']))
        ->toThrow(ValidationException::class);
});

it('still lets an archived record save itself when it is restored and edited', function () {
    $this->archivedClient->restore();

    $this->archivedClient->update(['country' => 'Austria']);

    expect($this->archivedClient->fresh()->country)->toBe('Austria');
});

it('leaves unrelated values alone', function () {
    $client = Client::create(['ref_no' => '0999', 'name' => 'Different GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);

    expect($client->exists)->toBeTrue();
});

it('restores an archived master from the command line', function () {
    $this->artisan('crm:archived-masters')->expectsOutputToContain('Acme GmbH')->assertSuccessful();

    $this->artisan('crm:archived-masters', ['--restore' => 'Client:'.$this->archivedClient->id])->assertSuccessful();

    expect(Client::where('name', 'Acme GmbH')->exists())->toBeTrue();
    $this->artisan('crm:archived-masters')->expectsOutputToContain('No archived master records.')->assertSuccessful();
});

it('rejects an import row whose name belongs to an archived client', function () {
    $book = IOFactory::load(base_path('docs/CRM_Master_Data.xlsx'));
    $book->getSheetByName('Clients')->fromArray([['Acme GmbH', 'Foreign', 'Germany', '', '', '', '', '', '', '', 'USD', '', '0777']], null, 'A2');
    $path = tempnam(sys_get_temp_dir(), 'archived').'.xlsx';

    try {
        (new Xlsx($book))->save($path);
        $preview = app(MasterImportService::class)->preview($path);

        expect($preview['rejected'])->toBeGreaterThan(0)
            ->and(implode(' ', array_column($preview['errors'], 'error')))->toContain('archived client (#'.$this->archivedClient->id.')');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('rejects a restore target that does not make sense', function () {
    $this->artisan('crm:archived-masters', ['--restore' => 'Invoice:1'])->assertFailed();
    $this->artisan('crm:archived-masters', ['--restore' => 'Client:4242'])->assertFailed();
});
