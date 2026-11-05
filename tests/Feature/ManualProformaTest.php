<?php

use App\Filament\Resources\InvoiceResource\Pages\ManageRecords;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ManualProformaService;
use App\Services\PaymentService;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->actor = User::factory()->create(['is_active' => true]);
    $this->actor->assignRole('Accountant Assistant');
    $this->actingAs($this->actor);
    $this->data = [
        'name' => 'New walk-in client', 'contact_person' => 'Demo contact', 'address' => 'Billing address',
        'email_1' => 'billing@example.test', 'country' => 'USA', 'phone' => '+1 555 0100', 'client_type' => 'Foreign',
        'invoice_layout' => 'detailed', 'currency' => 'USD', 'invoice_date' => today()->toDateString(), 'due_date' => today()->addDays(15)->toDateString(),
        'payment_method' => 'bank', 'bank_account_id' => BankAccount::where('currency', 'USD')->value('id'),
        'items' => [['work' => 'National phase filing', 'applicant_name' => 'Applicant A', 'official_amount' => '1000', 'professional_amount' => '500']],
    ];
});

it('creates a reference-free proforma without inventing references or master matters', function () {
    $this->data['items'][] = ['work' => 'National phase filing', 'professional_amount' => '200'];
    $invoice = app(ManualProformaService::class)->create($this->actor, $this->data);
    expect($invoice->client->ref_no)->toBeNull()->and(Matter::count())->toBe(0)
        ->and($invoice->items->pluck('our_ref')->filter())->toHaveCount(0)
        ->and($invoice->items->pluck('entry_key')->unique())->toHaveCount(2)
        ->and($invoice->grand_total)->toBe('1700.00');
    $pages = app(PdfService::class)->pages($invoice);
    expect(collect($pages)->flatMap(fn ($page) => $page['items'])->pluck('matter_index')->unique())->toHaveCount(2);
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => $pages, 'settings' => []])->render();
    expect($html)->toContain('Applicant A')->not->toContain('Our ref:')->not->toContain('Your ref:');
});

it('preserves invoice billing details when the client master is later changed', function () {
    $invoice = app(ManualProformaService::class)->create($this->actor, $this->data);
    $invoice->client->update(['address' => 'New master address', 'name' => 'Renamed master client', 'client_type' => 'Indian', 'country' => 'India']);
    $invoice->refresh();
    expect($invoice->billingClient()->address)->toBe('Billing address')
        ->and($invoice->billingClient()->name)->toBe('New walk-in client')
        ->and($invoice->client->address)->toBe('New master address')
        ->and(app(InvoiceService::class)->recalculate($invoice)->tax_total)->toBe('0.00');
});

it('taxes only Indian professional fees and keeps separate amounts internally when printed combined', function (string $layout) {
    $this->data = array_replace($this->data, ['client_type' => 'Indian', 'country' => 'India', 'currency' => 'INR', 'state' => 'Uttar Pradesh', 'gst_enabled' => true, 'gst_rate' => '18', 'invoice_layout' => $layout, 'bank_account_id' => BankAccount::where('currency', 'INR')->value('id')]);
    $this->data['additional_charges'] = [['label' => 'Gateway charge', 'amount' => '10']];
    $invoice = app(ManualProformaService::class)->create($this->actor, $this->data);
    expect($invoice->tax_total)->toBe('90.00')->and($invoice->grand_total)->toBe('1600.00')
        ->and($invoice->items)->toHaveCount(2)->and($invoice->invoice_layout)->toBe('india_gst');
    $pages = app(PdfService::class)->pages($invoice);
    expect($pages[0]['items'])->toHaveCount($layout === 'combined' ? 1 : 2);
})->with(['combined', 'detailed']);

it('supports opting out of GST and selecting a configured gateway', function () {
    $this->data = array_replace($this->data, ['client_type' => 'Indian', 'country' => 'India', 'currency' => 'INR', 'gst_enabled' => false, 'payment_method' => 'gateway', 'gateway_id' => PaymentGateway::where('name', 'Skydo')->value('id')]);
    $invoice = app(ManualProformaService::class)->create($this->actor, $this->data);
    expect($invoice->tax_total)->toBe('0.00')->and($invoice->grand_total)->toBe('1500.00')->and($invoice->bank_account_id)->toBeNull();
    $pages = app(PdfService::class)->pages($invoice);
    $html = view('pdf.invoice', ['invoice' => $invoice, 'pages' => $pages, 'settings' => []])->render();
    expect($html)->toContain('GST not applied', 'Skydo')->not->toContain('Bank details pending');
});

it('validates the translation language and preserves applicant and optional identifiers', function () {
    $this->data['items'][0] += ['translation_amount' => '200', 'language' => 'Japanese', 'app_no' => 'APP-1', 'pct_no' => 'PCT-1', 'our_ref' => 'Optional-ref'];
    $invoice = app(ManualProformaService::class)->create($this->actor, $this->data);
    expect($invoice->items)->toHaveCount(3)->and($invoice->grand_total)->toBe('1700.00')
        ->and($invoice->items[2]->language)->toBe('Japanese')->and($invoice->items[2]->applicant_name)->toBe('Applicant A');
});

it('rejects inactive or mismatched payment options without creating any invoice', function () {
    $this->data['bank_account_id'] = BankAccount::where('currency', 'INR')->value('id');
    expect(fn () => app(ManualProformaService::class)->create($this->actor, $this->data))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0)->and(Client::count())->toBe(0);
    $gateway = PaymentGateway::first();
    $gateway->update(['is_active' => false]);
    $this->data['payment_method'] = 'gateway';
    $this->data['gateway_id'] = $gateway->id;
    expect(fn () => app(ManualProformaService::class)->create($this->actor, $this->data))->toThrow(ValidationException::class);
});

it('requires fees and rolls back a new client when matter fees are missing', function () {
    $this->data['items'] = [['work' => 'Filing']];
    expect(fn () => app(ManualProformaService::class)->create($this->actor, $this->data))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0)->and(Client::count())->toBe(0);
});

it('allows explicitly delegated creation without adding payment or approval permissions', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('Paralegal');
    expect($user->allowed('accounting.invoices.create'))->toBeFalse();
    $user->update(['can_create_proforma' => true]);
    $this->actingAs($user);
    expect($user->allowed('accounting.invoices.create'))->toBeTrue()
        ->and($user->allowed('payments.mark_received'))->toBeFalse()
        ->and($user->allowed('accounting.tax.approve'))->toBeFalse();
    Livewire::test(ManageRecords::class)->callAction('create', $this->data)->assertHasNoActionErrors();
    expect(Invoice::count())->toBe(1);
});

it('rejects creation for unapproved or inactive users', function () {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('Director');
    expect(fn () => app(ManualProformaService::class)->create($user, $this->data))->toThrow(HttpException::class);
    $user->update(['is_active' => false, 'can_create_proforma' => true]);
    expect(fn () => app(ManualProformaService::class)->create($user, $this->data))->toThrow(HttpException::class);
});

it('keeps the existing finalise payment approve and tax conversion workflow for reference-free invoices', function () {
    $invoice = app(ManualProformaService::class)->create($this->actor, $this->data);
    $invoice = app(InvoiceService::class)->send($invoice);
    $accountant = User::factory()->create(['is_active' => true]);
    $accountant->assignRole('Accountant');
    $this->actingAs($accountant);
    app(PaymentService::class)->receive($invoice, ['received_on' => today()->toDateString(), 'currency' => 'USD', 'amount' => '1500', 'bank_account_id' => $this->data['bank_account_id'], 'txn_ref' => 'DEMO-PAID'], $accountant);
    $tax = app(InvoiceService::class)->orderTax($invoice->fresh(), today()->toDateString(), $accountant);
    expect($tax->doc_type)->toBe('tax')->and($tax->items)->toHaveCount(2)->and($tax->billing_details['name'])->toBe('New walk-in client');
});

it('treats cleared optional references as null and permits multiple new clients without references', function () {
    $this->data['client_ref'] = '';
    $this->data['client_id'] = '';
    $one = app(ManualProformaService::class)->create($this->actor, $this->data);
    $this->data['name'] = 'Another new client';
    $two = app(ManualProformaService::class)->create($this->actor, $this->data);
    expect($one->client->ref_no)->toBeNull()->and($two->client->ref_no)->toBeNull()->and(Client::count())->toBe(2);
});

it('rolls back untranslated or unpriced matters and rejects hiding translation in combined fees', function () {
    $this->data['items'][0]['translation_amount'] = '100';
    expect(fn () => app(ManualProformaService::class)->create($this->actor, $this->data))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(0)->and(Client::count())->toBe(0);
    $this->data['items'][0]['language'] = 'French';
    $this->data['invoice_layout'] = 'combined';
    expect(fn () => app(ManualProformaService::class)->create($this->actor, $this->data))->toThrow(ValidationException::class);
});

it('does not pick an unrelated no-reference client when the optional reference is cleared', function () {
    Client::create(['name' => 'Another client with no ref', 'client_type' => 'Foreign', 'country' => 'USA', 'default_currency' => 'USD']);
    Livewire::test(ManageRecords::class)->mountAction('create')
        ->set('mountedActions.0.data.name', 'Typed new client')
        ->set('mountedActions.0.data.client_ref', '')
        ->assertSet('mountedActions.0.data.name', 'Typed new client')
        ->assertSet('mountedActions.0.data.client_id', null);
});
