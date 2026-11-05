<?php

use App\Models\BankAccount;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('adds demo options idempotently without changing real bank details or defaults', function () {
    $real = BankAccount::create(['bank_name' => 'Existing bank', 'currency' => 'INR', 'account_no' => 'UNCHANGED', 'is_default' => true]);
    $this->artisan('crm:demo-payment-options')->assertSuccessful();
    $this->artisan('crm:demo-payment-options')->assertSuccessful();
    expect(BankAccount::where('bank_name', 'like', '%(DEMO)')->count())->toBe(6)
        ->and(PaymentGateway::where('name', 'like', '%(DEMO)')->count())->toBe(3)
        ->and($real->fresh()->account_no)->toBe('UNCHANGED')
        ->and(BankAccount::where('bank_name', 'like', '%(DEMO)')->where('is_default', true)->count())->toBe(0);
});

it('refuses demo payment options in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('crm:demo-payment-options')->assertFailed();
    expect(BankAccount::count())->toBe(0)->and(PaymentGateway::count())->toBe(0);
});
