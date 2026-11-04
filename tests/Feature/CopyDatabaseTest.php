<?php

use App\Console\Commands\CopyDatabase;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->source = DB::getDefaultConnection();

    $this->directory = storage_path('framework/testing/copy-'.uniqid());
    File::ensureDirectoryExists($this->directory);
    $file = $this->directory.DIRECTORY_SEPARATOR.'target.sqlite';
    File::put($file, '');
    config(['database.connections.copy_target' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);

    // Migrating a connection switches the default one; the source has to come back.
    $this->artisan('migrate', ['--database' => 'copy_target', '--force' => true])->assertSuccessful();
    DB::setDefaultConnection($this->source);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('Accountant');
    $this->actingAs($this->admin);
    $client = Client::create(['ref_no' => '0901', 'name' => 'Copy Client', 'client_type' => 'Foreign', 'country' => 'France', 'default_currency' => 'USD']);
    $para = Paralegal::create(['name' => 'Copy Paralegal']);
    Matter::create(['client_id' => $client->id, 'paralegal_id' => $para->id, 'ref_no' => '0901', 'our_ref' => 'P26090101FR', 'matter_title' => 'Filing', 'country' => 'France', 'service_type' => 'Professional Fees']);
    $service = app(InvoiceService::class);
    $this->invoice = $service->send($service->draft($this->admin, $client->id, 'USD', [['matter_ref' => 'P26090101FR', 'fee_type' => 'Professional Fees', 'rate' => '250']]));
});

afterEach(function () {
    DB::purge('copy_target');
    File::deleteDirectory($this->directory);
});

it('copies every table and leaves the foreign keys pointing at rows that exist', function () {
    $this->artisan('crm:db-copy', ['--from' => $this->source, '--to' => 'copy_target', '--force' => true])->assertSuccessful();

    $target = DB::connection('copy_target');
    foreach (['users', 'clients', 'matters', 'paralegals', 'invoices', 'invoice_items'] as $table) {
        expect($target->table($table)->count())->toBe(DB::table($table)->count(), $table.' did not copy in full');
    }

    expect($target->table('invoices')->where('id', $this->invoice->id)->value('number'))->toBe($this->invoice->number)
        ->and($target->table('invoice_items')->whereNotIn('invoice_id', $target->table('invoices')->pluck('id'))->count())->toBe(0);
});

it('replaces the rows a fresh migration seeds instead of merging onto them', function () {
    $target = DB::connection('copy_target');
    expect($target->table('roles')->count())->toBeGreaterThan(0);

    $this->artisan('crm:db-copy', ['--from' => $this->source, '--to' => 'copy_target', '--force' => true])->assertSuccessful();

    // The source's own ids have to survive, so seeded rows are replaced, not added to.
    expect($target->table('roles')->count())->toBe(DB::table('roles')->count())
        ->and($target->table('users')->where('id', $this->admin->id)->value('email'))->toBe($this->admin->email);
});

it('refuses to replace an occupied target unless it is forced', function () {
    $this->artisan('crm:db-copy', ['--from' => $this->source, '--to' => 'copy_target'])->assertFailed();

    expect(DB::connection('copy_target')->table('invoices')->count())->toBe(0);
});

it('refuses to copy a connection onto itself', function () {
    $this->artisan('crm:db-copy', ['--from' => $this->source, '--to' => $this->source, '--force' => true])->assertFailed();
});

it('leaves the cache and session tables on the machine they belong to', function () {
    DB::table('cache')->insert(['key' => 'local-only', 'value' => 'cached', 'expiration' => now()->addHour()->timestamp]);

    $this->artisan('crm:db-copy', ['--from' => $this->source, '--to' => 'copy_target', '--force' => true])->assertSuccessful();

    expect(DB::connection('copy_target')->table('cache')->count())->toBe(0)
        ->and(CopyDatabase::SKIP)->toContain('sessions', 'jobs', 'migrations');
});

it('needs the target migrated before it will copy anything', function () {
    DB::connection('copy_target')->table('migrations')->delete();

    $this->artisan('crm:db-copy', ['--from' => $this->source, '--to' => 'copy_target', '--force' => true])->assertFailed();
});

it('fits a loosely typed value to the column it lands in', function () {
    // Postgres rejects the 0 and 1 SQLite hands back for a boolean.
    expect(CopyDatabase::value(1, 'bool'))->toBeTrue()
        ->and(CopyDatabase::value(0, 'bool'))->toBeFalse()
        ->and(CopyDatabase::value(null, 'bool'))->toBeNull()
        ->and(CopyDatabase::value('', 'timestamp'))->toBeNull()
        ->and(CopyDatabase::value('', 'numeric'))->toBeNull()
        ->and(CopyDatabase::value('', 'varchar'))->toBe('')
        ->and(CopyDatabase::value('PI260001', 'varchar'))->toBe('PI260001')
        ->and(CopyDatabase::value('2026-11-04 09:00:00', 'timestamp'))->toBe('2026-11-04 09:00:00');
});
