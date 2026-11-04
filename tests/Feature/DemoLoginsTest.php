<?php

use App\Console\Commands\DisableDemoLogins;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('switches off the built-in demo logins and leaves real accounts alone', function () {
    foreach (DisableDemoLogins::EMAILS as $email) {
        User::factory()->create(['email' => $email, 'is_active' => true])->assignRole('Accountant');
    }
    $real = User::factory()->create(['email' => 'owner@firm.test', 'is_active' => true]);

    $this->artisan('crm:disable-demo-logins')->assertSuccessful();

    expect(User::whereIn('email', DisableDemoLogins::EMAILS)->where('is_active', true)->count())->toBe(0)
        ->and($real->fresh()->is_active)->toBeTrue();
});

it('reports an active demo login as a failure without switching it off', function () {
    User::factory()->create(['email' => 'director@demo.test', 'is_active' => true])->assignRole('Director');

    $this->artisan('crm:disable-demo-logins', ['--list' => true])->assertFailed();

    expect(User::where('email', 'director@demo.test')->first()->is_active)->toBeTrue();
});

it('passes the demo login check when none is active', function () {
    $this->artisan('crm:disable-demo-logins', ['--list' => true])->assertSuccessful();
});

it('blocks a disabled demo login from signing in', function () {
    User::factory()->create(['email' => 'paralegal@demo.test', 'password' => 'Demo@12345', 'is_active' => true])->assignRole('Paralegal');
    $this->artisan('crm:disable-demo-logins')->assertSuccessful();

    $this->post('/login', ['email' => 'paralegal@demo.test', 'password' => 'Demo@12345'])->assertSessionHasErrors('email');
    $this->assertGuest();
});
