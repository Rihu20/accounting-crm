<?php

use App\Filament\Resources\SettingResource\Pages\ManageRecords as SettingsPage;
use App\Models\Expense;
use App\Models\RemittanceBank;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DataBackupService;
use App\Services\RemittanceFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->accountant = User::factory()->create(['is_active' => true]);
    $this->accountant->assignRole('Accountant');
    $this->actingAs($this->accountant);
    $this->vendor = Vendor::create(['name' => 'Wolf Mendez Abogados', 'country' => 'Colombia', 'vendor_type' => 'Foreign', 'default_currency' => 'USD', 'bank_account' => '1040121773', 'swift' => 'HELMUS33']);
    $this->bill = Expense::create(['expense_type' => 'vendor', 'expense_group' => 'foreign', 'invoice_date' => today(), 'amount' => '325', 'currency' => 'USD', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->accountant->id, 'category' => 'Law firm', 'status' => 'Unpaid', 'vendor_id' => $this->vendor->id, 'payee_name' => $this->vendor->name, 'vendor_invoice_no' => 'PI-28893']);
    RemittanceBank::create(['bank_name' => 'Deutsche Bank', 'account_no' => 'EEFC-1', 'currency' => 'USD']);
    $service = app(RemittanceFormService::class);
    $service->prepare($this->bill, $service->defaults($this->bill), $this->accountant);
});

it('puts every table after the tables it points to', function () {
    $order = app(DataBackupService::class)->insertOrder(['remittance_form_expense', 'expenses', 'remittance_forms', 'vendors', 'users', 'remittance_banks']);
    $at = array_flip($order);
    expect($at['vendors'])->toBeLessThan($at['expenses'])
        ->and($at['expenses'])->toBeLessThan($at['remittance_form_expense'])
        ->and($at['remittance_forms'])->toBeLessThan($at['remittance_form_expense'])
        ->and($at['remittance_banks'])->toBeLessThan($at['remittance_forms'])
        ->and($at['users'])->toBeLessThan($at['remittance_forms']);
});

it('backs up every record and restores it exactly, keeping the ids', function () {
    $service = app(DataBackupService::class);
    $backup = json_decode($service->exportJson(), true);

    expect($backup['format'])->toBe('analystip-crm-backup')
        ->and($backup['tables'])->toHaveKeys(['users', 'settings', 'vendors', 'expenses', 'remittance_forms', 'remittance_form_expense', 'activity_log', 'roles', 'model_has_roles'])
        ->not->toHaveKeys(['migrations', 'sessions', 'cache'])
        ->and(collect($backup['tables']['vendors'])->firstWhere('name', 'Wolf Mendez Abogados')['bank_account'])->toBe('1040121773');

    $before = collect($backup['tables'])->map(fn ($rows) => count($rows))->all();
    $this->vendor->update(['bank_account' => 'CHANGED']);
    Setting::where('key', 'company_name')->update(['value' => 'Changed Ltd']);
    Expense::create(['expense_type' => 'owner', 'expense_group' => 'indian', 'category' => 'Admin expense', 'invoice_date' => today(), 'amount' => 5, 'currency' => 'INR', 'paid_by' => 'cash', 'paid_by_user_id' => $this->accountant->id, 'status' => 'Paid']);

    $counts = $service->restore($backup);

    expect($counts)->toBe($before)
        ->and($this->vendor->fresh()->bank_account)->toBe('1040121773')
        ->and(Setting::get('company_name'))->toBe('ANALYSTIP LEGAL SERVICES PVT LTD')
        ->and(Expense::count())->toBe(1)
        ->and($this->bill->fresh()->remittanceForms()->count())->toBe(1)
        ->and(User::find($this->accountant->id)->hasRole('Accountant'))->toBeTrue();

    // New records after a restore get fresh ids, not ones already used.
    $next = Vendor::create(['name' => 'After restore', 'country' => 'Japan', 'vendor_type' => 'Foreign', 'default_currency' => 'JPY']);
    expect($next->id)->toBeGreaterThan($this->vendor->id);
});

it('restores from a file with the command and refuses a file that is not a backup', function () {
    $path = tempnam(sys_get_temp_dir(), 'bk');
    file_put_contents($path, app(DataBackupService::class)->exportJson());
    $this->vendor->update(['swift' => 'XXXXXXXX']);

    $this->artisan('crm:restore-backup', ['file' => $path, '--force' => true])->expectsOutputToContain('Restore complete')->assertSuccessful();
    expect($this->vendor->fresh()->swift)->toBe('HELMUS33');

    file_put_contents($path, json_encode(['hello' => 'world']));
    $this->artisan('crm:restore-backup', ['file' => $path, '--force' => true])->expectsOutputToContain('not an AnalystIP CRM backup')->assertFailed();
    expect($this->vendor->fresh()->swift)->toBe('HELMUS33');
    unlink($path);
});

it('lets the Accountant download the backup from Settings, and nobody else', function () {
    Livewire::test(SettingsPage::class)->assertActionVisible('backup')->callAction('backup')->assertFileDownloaded();

    $director = User::factory()->create(['is_active' => true]);
    $director->assignRole('Director');
    $this->actingAs($director);
    expect($director->allowed('accounting.settings.manage'))->toBeFalse();
});
