<?php

use App\Filament\Resources\ClientResource\Pages\ManageRecords;
use App\Models\Client;
use App\Models\User;
use App\Services\MasterDataChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('flags incomplete Indian billing details without changing saved records', function () {
    $client = Client::create(['name' => 'Indian review client', 'client_type' => 'Indian', 'country' => 'India', 'default_currency' => 'USD']);
    $checks = app(MasterDataChecks::class)->clients();
    foreach (['reference', 'address', 'currency', 'state', 'email'] as $key) {
        expect($checks[$key]['records'])->toContain(['id' => $client->id, 'name' => $client->name, 'ref_no' => null]);
    }
    expect($client->fresh()->default_currency)->toBe('USD');
    $client->update(['ref_no' => '1214', 'address' => 'Noida billing address', 'state' => 'Uttar Pradesh', 'email_2' => 'billing@example.test', 'default_currency' => 'INR']);
    foreach (app(MasterDataChecks::class)->clients() as $check) {
        expect(array_column($check['records'], 'id'))->not->toContain($client->id);
    }
});

it('counts every issue but caps samples and excludes archived clients', function () {
    for ($i = 0; $i < 23; $i++) {
        Client::create(['name' => 'Review '.$i, 'client_type' => 'Foreign', 'country' => 'USA', 'default_currency' => 'USD']);
    }
    Client::where('name', 'Review 0')->first()->delete();
    $check = app(MasterDataChecks::class)->clients()['reference'];
    expect($check['count'])->toBe(22)->and($check['records'])->toHaveCount(20);
});

it('accepts a valid state code or GSTIN prefix as place of supply', function () {
    $client = Client::create(['name' => 'Registered client', 'client_type' => 'Indian', 'country' => 'India', 'default_currency' => 'INR', 'gstin' => '09ABBCA7682H1ZD']);
    expect(app(MasterDataChecks::class)->clients()['state']['count'])->toBe(0);
    $client->update(['gstin' => null, 'state_code' => '27']);
    expect(app(MasterDataChecks::class)->clients()['state']['count'])->toBe(0);
    $client->update(['state_code' => '99']);
    expect(app(MasterDataChecks::class)->clients()['state']['count'])->toBe(1);
});

it('only exposes data checks to roles allowed to edit masters', function (string $role, int $status) {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);
    $this->actingAs($user)->get('/admin/data-checks')->assertStatus($status);
})->with([['Accountant', 200], ['Accountant Assistant', 200], ['Director', 403], ['Paralegal', 403]]);

it('sets Indian billing defaults on client type selection and allows deliberate overrides', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('Accountant');
    $this->actingAs($user);
    Livewire::test(ManageRecords::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.client_type', 'Indian')
        ->assertSet('mountedActions.0.data.country', 'India')
        ->assertSet('mountedActions.0.data.default_currency', 'INR')
        ->set('mountedActions.0.data.default_currency', 'USD')
        ->assertSet('mountedActions.0.data.default_currency', 'USD');
});
