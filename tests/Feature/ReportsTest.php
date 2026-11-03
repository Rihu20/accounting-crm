<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function reportUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/** A vendor bill that skips the Indian GST auto-calculation, so the amount stays exactly as given. */
function foreignBill(User $payer, string $amount, ?Carbon $dueDate): Expense
{
    return Expense::create([
        'expense_type' => 'vendor', 'expense_group' => 'foreign', 'category' => 'Law firm', 'payee_name' => 'Overseas Counsel LLP',
        'invoice_date' => today()->subDays(60), 'due_date' => $dueDate, 'currency' => 'USD', 'amount' => $amount,
        'paid_by' => 'bank', 'paid_by_user_id' => $payer->id, 'status' => 'Unpaid', 'reimbursement_status' => 'Pending',
    ]);
}

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $this->accountant = reportUser('Accountant');
    $this->assistant = reportUser('Accountant Assistant');
    $this->client = Client::create(['ref_no' => '0777', 'name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD']);
    $paralegal = Paralegal::create(['name' => 'Seema', 'user_id' => reportUser('Paralegal')->id]);
    Matter::create(['client_id' => $this->client->id, 'paralegal_id' => $paralegal->id, 'ref_no' => '0777', 'our_ref' => 'P26077701DE', 'matter_title' => 'Filing', 'country' => 'Germany', 'service_type' => 'Professional Fees']);
    $this->actingAs($this->accountant);
    $this->sent = app(InvoiceService::class)->send(app(InvoiceService::class)->draft($this->accountant, $this->client->id, 'USD', [['matter_ref' => 'P26077701DE', 'fee_type' => 'Professional Fees', 'rate' => '1000']]));
    $this->reports = app(ReportService::class);
});

it('buckets unpaid vendor bills by how far past their due date they are', function () {
    foreignBill($this->accountant, '100.00', today());
    foreignBill($this->accountant, '100.00', today()->addDays(10));
    foreignBill($this->accountant, '100.00', today()->subDays(45));

    $ageing = $this->reports->payablesAgeing();

    expect($ageing['USD']['0-30'])->toBe('100.00')
        ->and($ageing['USD']['Not due'])->toBe('100.00')
        ->and($ageing['USD']['31-60'])->toBe('100.00')
        ->and($ageing['USD']['61-90'])->toBe('0.00')
        ->and($ageing['USD']['total'])->toBe('300.00')
        ->and($ageing['USD']['count'])->toBe(3);
});

it('falls back to the bill date when a vendor bill has no due date', function () {
    foreignBill($this->accountant, '250.00', null);

    expect($this->reports->payablesAgeing()['USD']['31-60'])->toBe('250.00');

    $rows = $this->reports->payablesAgeingRows();
    expect($rows[1][1])->toBe('Overseas Counsel LLP')
        ->and($rows[1][8])->toBe('')
        ->and($rows[1][9])->toBe(60)
        ->and($rows[1][10])->toBe('31-60');
});

it('lets report roles download the payables ageing and keeps the assistant out', function () {
    foreignBill($this->accountant, '100.00', today());

    $this->get('/crm/reports/payables-ageing')->assertOk();
    $this->actingAs($this->assistant)->get('/crm/reports/payables-ageing')->assertForbidden();
});

it('builds a TDS register from client receipts and vendor bills', function () {
    app(PaymentService::class)->receive($this->sent, [
        'received_on' => today()->toDateString(), 'amount' => '900', 'tds_amount' => '100', 'currency' => 'USD',
        'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'WIRE-1',
    ], $this->accountant);

    Expense::create([
        'expense_type' => 'vendor', 'expense_group' => 'indian', 'category' => 'Law firm', 'payee_name' => 'Desi Associates',
        'vendor_invoice_no' => 'DA/26/14', 'invoice_date' => today(), 'currency' => 'INR', 'amount' => 0,
        'taxable_amount' => '10000', 'gst_rate' => '18', 'gst_mode' => 'igst', 'tds_rate' => '10', 'tds_section' => '194J',
        'paid_by' => 'bank', 'paid_by_user_id' => $this->accountant->id, 'status' => 'Unpaid', 'reimbursement_status' => 'Pending',
    ]);

    [$start, $end] = ReportService::financialYear();
    $sheets = $this->reports->tds($start->toDateString(), $end->toDateString());

    expect($sheets['Receipts TDS'])->toHaveCount(2)
        ->and($sheets['Receipts TDS'][1][2])->toBe($this->sent->fresh()->number)
        ->and($sheets['Receipts TDS'][1][4])->toBe('Acme GmbH')
        ->and($sheets['Receipts TDS'][1][8])->toBe('100.00')
        ->and($sheets['Vendor Bill TDS'])->toHaveCount(2)
        ->and($sheets['Vendor Bill TDS'][1][1])->toBe('Desi Associates')
        ->and($sheets['Vendor Bill TDS'][1][3])->toBe('194J')
        ->and($sheets['Vendor Bill TDS'][1][7])->toBe('1000.00');

    expect(collect($sheets['Summary']))->toContain(['Acme GmbH', 'USD', 1, '100.00'])
        ->and(collect($sheets['Summary']))->toContain(['194J', 'INR', 1, '1000.00']);
});

it('leaves receipts without TDS out of the register', function () {
    app(PaymentService::class)->receive($this->sent, [
        'received_on' => today()->toDateString(), 'amount' => '1000', 'currency' => 'USD',
        'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'WIRE-FULL',
    ], $this->accountant);

    [$start, $end] = ReportService::financialYear();

    expect($this->reports->tds($start->toDateString(), $end->toDateString())['Receipts TDS'])->toHaveCount(1);
});

it('builds a client statement of account whose closing balance is the amount still due', function () {
    app(PaymentService::class)->receive($this->sent, [
        'received_on' => today()->toDateString(), 'amount' => '600', 'tds_amount' => '100', 'currency' => 'USD',
        'bank_account_id' => $this->sent->bank_account_id, 'txn_ref' => 'WIRE-2',
    ], $this->accountant);

    $sheets = $this->reports->clientStatement($this->client);
    $rows = $sheets['USD'];
    $particulars = collect($rows)->pluck(1);

    expect($sheets)->toHaveKey('USD')
        ->and($particulars)->toContain('Proforma', 'Payment received', 'TDS deducted')
        ->and(end($rows))->toBe(['', '', 'Closing balance due', '', '', '300.00'])
        ->and($this->sent->fresh()->outstandingAmount())->toBe('300.00');
});

it('states plainly when a client has nothing to put on a statement', function () {
    $fresh = Client::create(['ref_no' => '0999', 'name' => 'Nobody Ltd', 'client_type' => 'Indian', 'country' => 'India', 'default_currency' => 'INR']);

    expect($this->reports->clientStatement($fresh))->toHaveKey('Statement');
});

it('downloads a client statement and refuses an unknown client', function () {
    $this->get('/crm/reports/statement?client='.$this->client->id)->assertOk();
    $this->get('/crm/reports/statement?client=999999')->assertNotFound();
    $this->get('/crm/reports/statement')->assertInvalid('client');
});

it('resolves the Indian financial year around 1 April', function () {
    expect(ReportService::financialYear(Carbon::parse('2026-03-31'))[0]->toDateString())->toBe('2025-04-01')
        ->and(ReportService::financialYear(Carbon::parse('2026-03-31'))[1]->toDateString())->toBe('2026-03-31')
        ->and(ReportService::financialYear(Carbon::parse('2026-04-01'))[0]->toDateString())->toBe('2026-04-01')
        ->and(ReportService::financialYear(Carbon::parse('2026-04-01'))[1]->toDateString())->toBe('2027-03-31');
});

it('shows the new reports on the reports page', function () {
    $this->get('/admin/reports')->assertOk()->assertSee('Payables ageing')->assertSee('TDS register')->assertSee('Client statement of account');
});
