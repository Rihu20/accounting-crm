<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

// No RefreshDatabase here: SQLite refuses to VACUUM inside a transaction, and the command
// is meant to run against the real file database, not the in-memory test one.
uses(TestCase::class);

beforeEach(function () {
    $this->directory = storage_path('framework/testing/backup-'.uniqid());
    File::ensureDirectoryExists($this->directory);
    $this->source = $this->directory.DIRECTORY_SEPARATOR.'source.sqlite';
    File::put($this->source, '');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->source]);
    DB::purge('sqlite');
    DB::statement('create table invoices (id integer primary key, number text)');
    DB::table('invoices')->insert(['number' => 'PI260001']);
});

afterEach(function () {
    DB::purge('sqlite');
    File::deleteDirectory($this->directory);
});

it('writes a dated copy of the database that can be opened on its own', function () {
    $this->artisan('crm:backup', ['--path' => $this->directory])->assertSuccessful();

    $backups = File::glob($this->directory.DIRECTORY_SEPARATOR.'database-*.sqlite');
    expect($backups)->toHaveCount(1);

    $copy = new PDO('sqlite:'.$backups[0]);
    expect($copy->query('select number from invoices')->fetchColumn())->toBe('PI260001');
});

it('keeps only the requested number of backups', function () {
    foreach (['database-20260101-000000.sqlite', 'database-20260102-000000.sqlite', 'database-20260103-000000.sqlite'] as $old) {
        File::put($this->directory.DIRECTORY_SEPARATOR.$old, 'older backup');
    }

    $this->artisan('crm:backup', ['--path' => $this->directory, '--keep' => 2])->assertSuccessful();

    expect(File::glob($this->directory.DIRECTORY_SEPARATOR.'database-*.sqlite'))->toHaveCount(2);
});

it('refuses to pretend it backed up a MySQL database', function () {
    config(['database.default' => 'mysql']);

    $this->artisan('crm:backup', ['--path' => $this->directory])->assertFailed();

    expect(File::glob($this->directory.DIRECTORY_SEPARATOR.'database-*.sqlite'))->toBeEmpty();
});
