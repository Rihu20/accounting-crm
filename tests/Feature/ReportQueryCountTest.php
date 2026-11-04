<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Reports must cost the same number of queries whatever the number of rows. */
function queryCount(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $work();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}

function makeInvoices(int $count, User $actor, Client $client): void
{
    $start = Invoice::max('id') ?? 0;
    for ($n = 1; $n <= $count; $n++) {
        $serial = $start + $n;
        $proforma = Invoice::create([
            'doc_type' => 'proforma', 'number' => 'PI26'.str_pad((string) $serial, 4, '0', STR_PAD_LEFT), 'client_id' => $client->id,
            'invoice_date' => today()->subDays(20), 'due_date' => today()->subDays(5), 'currency' => 'USD',
            'grand_total' => '1000.00', 'status' => 'Partially received', 'sent_at' => now()->subDays(20), 'created_by' => $actor->id,
        ]);
        Payment::create([
            'invoice_id' => $proforma->id, 'amount' => '600.00', 'tds_amount' => '100.00', 'currency' => 'USD',
            'txn_ref' => 'WIRE-'.$serial, 'received_on' => today()->subDays(3), 'marked_by' => $actor->id, 'reversed' => false,
        ]);
        Invoice::create([
            'doc_type' => 'tax', 'number' => 'AIP26'.str_pad((string) $serial, 4, '0', STR_PAD_LEFT), 'proforma_id' => $proforma->id,
            'client_id' => $client->id, 'invoice_date' => today()->subDays(2), 'due_date' => today(), 'currency' => 'USD',
            'grand_total' => '1000.00', 'status' => 'Issued', 'created_by' => $actor->id,
        ]);
    }
}

beforeEach(function () {
    $this->seed();
    $this->accountant = User::factory()->create(['is_active' => true]);
    $this->accountant->assignRole('Accountant');
    $this->client = Client::create(['ref_no' => '0777', 'name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    $this->actingAs($this->accountant);
    $this->reports = app(ReportService::class);
});

it('exports the payment tracker without a query per invoice', function () {
    makeInvoices(3, $this->accountant, $this->client);
    $small = queryCount(fn () => $this->get('/crm/payments/export')->assertOk());

    makeInvoices(6, $this->accountant, $this->client);
    $large = queryCount(fn () => $this->get('/crm/payments/export')->assertOk());

    expect($large)->toBeLessThanOrEqual($small);
});

it('builds the TDS register without a query per receipt', function () {
    [$from, $to] = ReportService::financialYear();
    $run = fn () => $this->reports->tds($from->toDateString(), $to->toDateString());

    makeInvoices(3, $this->accountant, $this->client);
    $small = queryCount($run);
    makeInvoices(6, $this->accountant, $this->client);
    $large = queryCount($run);

    expect($large)->toBeLessThanOrEqual($small);
});

it('builds receivables ageing without a query per proforma', function () {
    makeInvoices(3, $this->accountant, $this->client);
    $small = queryCount(fn () => $this->reports->ageingRows());
    makeInvoices(6, $this->accountant, $this->client);
    $large = queryCount(fn () => $this->reports->ageingRows());

    expect($large)->toBeLessThanOrEqual($small);
});

it('builds the six month summary from two queries', function () {
    makeInvoices(4, $this->accountant, $this->client);

    expect(queryCount(fn () => $this->reports->monthly()))->toBe(2);
});

it('builds a client statement without a query per invoice', function () {
    makeInvoices(3, $this->accountant, $this->client);
    $small = queryCount(fn () => $this->reports->clientStatement($this->client));
    makeInvoices(6, $this->accountant, $this->client);
    $large = queryCount(fn () => $this->reports->clientStatement($this->client));

    expect($large)->toBeLessThanOrEqual($small);
});

it('lists recent payments without a query per payment', function () {
    makeInvoices(3, $this->accountant, $this->client);
    $dashboard = DashboardService::for($this->accountant);

    expect(queryCount(fn () => $dashboard->recentPayments()))->toBeLessThanOrEqual(4);
});

it('still reports the right numbers after the query changes', function () {
    makeInvoices(1, $this->accountant, $this->client);
    $proforma = Invoice::where('doc_type', 'proforma')->first();
    $tax = Invoice::where('doc_type', 'tax')->first();

    expect($proforma->receivedAmount())->toBe('600.00')
        ->and($proforma->outstandingAmount())->toBe('300.00')
        ->and($tax->receivedAmount())->toBe('600.00')
        ->and($tax->registerPaymentTotal('tds_amount'))->toBe('100.00');
});
