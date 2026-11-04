<?php

use App\Models\User;
use App\Services\PlatformAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('seeds an empty database and creates an administrator who can sign in', function () {
    expect(User::count())->toBe(0);

    $this->artisan('crm:first-run')->assertSuccessful();

    $admin = User::where('email', 'admin@crm.invalid')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->hasRole(PlatformAccess::ACCOUNTANT))->toBeTrue()
        ->and($admin->hasRole(PlatformAccess::DIRECTOR))->toBeTrue();

    // The seeder has to have run too, or there is no bank account to invoice from.
    expect(DB::table('bank_accounts')->count())->toBeGreaterThan(0)
        ->and(DB::table('settings')->count())->toBeGreaterThan(0);
});

it('prints the password once and stores only its hash', function () {
    expect(Artisan::call('crm:first-run'))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('First administrator created', 'admin@crm.invalid');

    preg_match('/Password:\s+(\S+)/', $output, $matches);
    expect($matches[1] ?? '')->toHaveLength(16);

    $admin = User::where('email', 'admin@crm.invalid')->first();
    expect($admin->password)->not->toBe($matches[1])
        ->and(Hash::check($matches[1], $admin->password))->toBeTrue();
});

it('leaves a database that already has users alone', function () {
    User::factory()->create(['is_active' => true]);

    $this->artisan('crm:first-run')->assertSuccessful();

    expect(User::where('email', 'admin@crm.invalid')->exists())->toBeFalse()
        ->and(User::count())->toBe(1);
});

it('refuses to reuse an address that is already taken', function () {
    User::factory()->create(['email' => 'admin@crm.invalid']);

    $this->artisan('crm:first-run', ['--force' => true])->assertFailed();

    expect(User::where('email', 'admin@crm.invalid')->count())->toBe(1);
});
