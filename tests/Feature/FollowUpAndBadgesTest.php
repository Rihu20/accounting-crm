<?php

use App\Filament\Pages\Workspace;
use App\Filament\Resources\ExpenseResource;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Setting;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $role = function (string $name): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($name);

        return $user;
    };
    $this->accountant = $role('Accountant');
    $this->paralegal = $role('Paralegal');
    $this->actingAs($this->accountant);
    $client = Client::create(['ref_no' => '0801', 'name' => 'Follow Up GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    Matter::create(['client_id' => $client->id, 'paralegal_id' => Paralegal::create(['name' => 'Seema'])->id, 'ref_no' => '0801', 'our_ref' => 'P26080101DE', 'matter_title' => 'Filing', 'country' => 'Germany', 'service_type' => 'Professional Fees']);
    $make = function (int $daysAgo) use ($client): Invoice {
        $service = app(InvoiceService::class);
        $invoice = $service->send($service->draft($this->accountant, $client->id, 'USD', [['matter_ref' => 'P26080101DE', 'fee_type' => 'Professional Fees', 'rate' => '500']]));
        $invoice->forceFill(['sent_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $invoice->fresh();
    };
    $this->old = $make(20);
    $this->recent = $make(5);
});

it('lists proformas due for a reminder by the follow-up days and repeat interval', function () {
    $dashboard = DashboardService::for($this->accountant);
    expect($dashboard->followUpsDue()->pluck('id')->all())->toBe([$this->old->id]);

    app(InvoiceService::class)->markReminderSent($this->old, $this->accountant);
    expect($this->old->fresh())->reminder_count->toBe(1)->last_reminder_on->isToday()->toBeTrue()
        ->and($dashboard->followUpsDue())->toBeEmpty()
        ->and(Activity::where('description', 'Payment reminder sent')->where('subject_id', $this->old->id)->exists())->toBeTrue();

    $this->old->forceFill(['last_reminder_on' => today()->subDays(7)])->saveQuietly();
    expect($dashboard->followUpsDue()->pluck('id')->all())->toBe([$this->old->id]);

    Setting::where('key', 'followup_days')->update(['value' => '3']);
    expect($dashboard->followUpsDue()->pluck('id')->all())->toBe([$this->old->id, $this->recent->id]);

    $this->old->update(['status' => 'Received']);
    expect($dashboard->followUpsDue()->pluck('id')->all())->toBe([$this->recent->id]);
});

it('records reminders only for unpaid proformas and only for those who see payments', function () {
    expect(fn () => app(InvoiceService::class)->markReminderSent($this->old, $this->paralegal->fresh()))->not->toThrow(HttpException::class);
    $this->old->update(['status' => 'Received']);
    expect(fn () => app(InvoiceService::class)->markReminderSent($this->old->fresh(), $this->accountant))->toThrow(HttpException::class, 'not fully paid');

    $director = User::factory()->create(['is_active' => true]);
    $director->assignRole('Director');
    $nobody = User::factory()->create(['is_active' => true]);
    $nobody->assignRole('Accountant Assistant');
    expect(fn () => app(InvoiceService::class)->markReminderSent($this->recent, $nobody))->toThrow(HttpException::class);
});

it('shows the follow-up card on the dashboard and records a reminder from it', function () {
    $this->get(Workspace::getUrl())->assertOk()->assertSee('Follow up today')->assertSee($this->old->number)->assertSee('no reminder yet')->assertDontSee($this->recent->number.' · sent');

    Livewire::test(Workspace::class)->callAction('reminder', [], ['invoice' => $this->old->id])->assertHasNoActionErrors();
    expect($this->old->fresh()->reminder_count)->toBe(1);
    $this->get(Workspace::getUrl())->assertSee('No reminder due today');
});

it('counts payment requests on the Expense register menu for those who review them', function () {
    expect(ExpenseResource::getNavigationBadge())->toBeNull();
    $base = ['expense_type' => 'vendor', 'expense_group' => 'foreign', 'invoice_date' => today(), 'amount' => '100', 'currency' => 'USD', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->accountant->id, 'category' => 'Law firm', 'status' => 'Unpaid', 'payee_name' => 'Firm'];
    Expense::create($base + ['vendor_invoice_no' => 'A', 'payment_request' => ['status' => 'Pending']]);
    Expense::create($base + ['vendor_invoice_no' => 'B', 'payment_request' => ['status' => 'Approved']]);
    expect(ExpenseResource::getNavigationBadge())->toBe('1');

    $this->actingAs($this->paralegal);
    expect(ExpenseResource::getNavigationBadge())->toBeNull();
});
