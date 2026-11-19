<?php

use App\Filament\Pages\ActivityLog;
use App\Filament\Pages\DataChecks;
use App\Filament\Pages\Workspace;
use App\Filament\Resources\ExpenseResource\Pages\ManageRecords as ExpensePage;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DashboardService;
use App\Services\MasterDataChecks;
use App\Services\RemittanceFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
    $this->vendor = Vendor::create(['name' => 'Harness, Dickey & Pierce', 'country' => 'United States', 'vendor_type' => 'Foreign', 'default_currency' => 'USD', 'bank_account' => '000123456789', 'bank_name' => 'Comerica Bank', 'swift' => 'MNBDUS33']);
    $this->base = ['expense_type' => 'vendor', 'expense_group' => 'foreign', 'invoice_date' => today(), 'amount' => '820.50', 'currency' => 'USD', 'paid_by' => 'company_bank', 'paid_by_user_id' => $this->accountant->id, 'category' => 'Law firm', 'status' => 'Unpaid', 'vendor_id' => $this->vendor->id, 'payee_name' => 'Harness, Dickey & Pierce'];
    $this->bill = Expense::create($this->base + ['vendor_invoice_no' => '699702', 'our_ref' => 'P25104305US']);
});

function docxText(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($path);

    return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)), ENT_QUOTES | ENT_XML1);
}

it('fills Form A2 from the bill, the vendor master and the settings', function () {
    $service = app(RemittanceFormService::class);
    $data = $service->defaults($this->bill);
    expect($data)->toMatchArray(['beneficiary_name' => 'Harness, Dickey & Pierce', 'bank_account' => '000123456789', 'swift' => 'MNBDUS33', 'charges' => 'OUR', 'purpose_code' => 'S1004', 'amount' => '820.50'])
        ->and($data['info'])->toContain('Invoice 699702', 'Our ref P25104305US');

    $form = $service->prepare($this->bill, $data, $this->accountant);
    $text = docxText($form['content']);

    expect($form['name'])->toBe('A2-Harness-Dickey-Pierce-'.today()->toDateString().'.docx')
        ->and($form['missing'])->toBe([])
        ->and($text)->toContain('FORM A2', 'Harness, Dickey & Pierce', '000123456789', 'MNBDUS33', 'USD 820.50', 'USD Eight Hundred Twenty and 50/100 Only', 'S1004', '☒ OUR', 'ABBCA7682H')
        ->and($this->bill->fresh()->a2_prepared_on->isToday())->toBeTrue()
        ->and(Activity::where('description', 'Bank Form A2 prepared')->where('subject_id', $this->bill->id)->exists())->toBeTrue();
});

it('names the blank bank details and keeps typed ones on the vendor only when asked', function () {
    $this->vendor->update(['bank_account' => null, 'swift' => null]);
    $service = app(RemittanceFormService::class);
    $form = $service->prepare($this->bill, $service->defaults($this->bill), $this->accountant);
    expect($form['missing'])->toBe(['beneficiary account / IBAN', 'SWIFT code']);

    $service->prepare($this->bill, ['bank_account' => 'DE89370400440532013000', 'swift' => 'COBADEFF'] + $service->defaults($this->bill), $this->accountant);
    expect($this->vendor->fresh()->bank_account)->toBeNull();

    $service->prepare($this->bill, ['bank_account' => 'DE89370400440532013000', 'swift' => 'COBADEFF', 'save_vendor' => true] + $service->defaults($this->bill), $this->accountant);
    expect($this->vendor->fresh())->bank_account->toBe('DE89370400440532013000')->swift->toBe('COBADEFF');
});

it('offers Form A2 only on foreign-currency vendor bills and only to those who pay or edit them', function () {
    $rupees = Expense::create(array_replace($this->base, ['expense_group' => 'indian', 'currency' => 'INR', 'vendor_invoice_no' => 'R-1', 'category' => 'Rent']));
    expect(RemittanceFormService::canPrepare($this->bill, $this->accountant))->toBeTrue()
        ->and(RemittanceFormService::canPrepare($rupees, $this->accountant))->toBeFalse()
        ->and(RemittanceFormService::canPrepare($this->bill, $this->paralegal))->toBeFalse();
    expect(fn () => app(RemittanceFormService::class)->prepare($this->bill, ['date' => today()->toDateString(), 'amount' => '1', 'beneficiary_name' => 'X', 'charges' => 'OUR'], $this->paralegal))
        ->toThrow(HttpException::class);

    Livewire::test(ExpensePage::class)->set('activeTab', 'foreign')
        ->assertTableActionVisible('bank_form_a2', $this->bill)
        ->callTableAction('bank_form_a2', $this->bill)
        ->assertHasNoTableActionErrors()
        ->assertFileDownloaded('A2-Harness-Dickey-Pierce-'.today()->toDateString().'.docx');
});

it('refuses the same vendor bill twice, whatever its case, spacing or punctuation', function () {
    expect(fn () => Expense::create($this->base + ['vendor_invoice_no' => ' 699-702 ']))->toThrow(ValidationException::class, 'already recorded');
    expect(fn () => Expense::create(array_replace($this->base, ['vendor_id' => null]) + ['vendor_invoice_no' => '699702']))->toThrow(ValidationException::class);

    $other = Vendor::create(['name' => 'CCPIT', 'country' => 'China', 'vendor_type' => 'Foreign', 'default_currency' => 'USD']);
    $chinese = Expense::create(array_replace($this->base, ['vendor_id' => $other->id, 'payee_name' => 'CCPIT']) + ['vendor_invoice_no' => '699702']);
    expect($chinese->exists)->toBeTrue();

    $this->bill->update(['amount' => '900']);
    expect($this->bill->fresh()->amount)->toBe('900.00');
    expect(fn () => $chinese->update(['vendor_id' => $this->vendor->id, 'payee_name' => 'Harness, Dickey & Pierce']))->toThrow(ValidationException::class);
});

it('requires the TDS section and rate together on Indian purchases', function () {
    $indian = array_replace($this->base, ['expense_group' => 'indian', 'currency' => 'INR', 'vendor_id' => null, 'payee_name' => 'Neeva Nora', 'category' => 'Rent', 'taxable_amount' => '10000', 'gst_mode' => 'igst', 'gst_rate' => '18']);
    expect(fn () => Expense::create($indian + ['tds_rate' => '10']))->toThrow(ValidationException::class, 'TDS section');
    expect(fn () => Expense::create($indian + ['tds_section' => '194J', 'tds_rate' => '0']))->toThrow(ValidationException::class, 'TDS rate is 0');
    expect(Expense::create($indian + ['tds_section' => '194J', 'tds_rate' => '10'])->tds_amount)->toBe('1000.00');
});

it('lists Indian bills whose PAN, GSTIN or GST details look wrong', function () {
    $indian = array_replace($this->base, ['expense_group' => 'indian', 'currency' => 'INR', 'vendor_id' => null, 'category' => 'Rent', 'taxable_amount' => '10000', 'gst_mode' => 'igst']);
    Expense::create(array_replace($indian, ['payee_name' => 'Good Vendor', 'vendor_invoice_no' => 'G1', 'pan_number' => 'AABCU9603R', 'gstin' => '27AABCU9603R1ZM', 'gst_rate' => '18', 'tds_section' => '194J', 'tds_rate' => '10']));
    Expense::create(array_replace($indian, ['payee_name' => 'No Pan', 'vendor_invoice_no' => 'B1', 'gst_rate' => '18', 'tds_section' => '194J', 'tds_rate' => '10']));
    Expense::create(array_replace($indian, ['payee_name' => 'Mismatch', 'vendor_invoice_no' => 'B2', 'pan_number' => 'AAAAA1111A', 'gstin' => '27AABCU9603R1ZM', 'gst_rate' => '0', 'tds_rate' => '0']));

    $bills = app(MasterDataChecks::class)->indianBills();
    $byParty = collect($bills['records'])->keyBy('party');
    expect($bills['count'])->toBe(2)
        ->and($byParty['No Pan']['problems'])->toBe(['TDS deducted but no PAN', 'GST charged but the vendor GSTIN is missing'])
        ->and($byParty['Mismatch']['problems'])->toBe(['PAN does not match the PAN inside the GSTIN', 'Vendor has a GSTIN but no GST rate is entered']);
    $this->get(DataChecks::getUrl())->assertOk()->assertSee('Indian vendor bills to review')->assertSee('No Pan');
});

it('shows who changed what to the Accountant and hides the log from a paralegal', function () {
    $this->bill->update(['status' => 'Paid', 'paid_ref' => 'SWIFT-77']);
    $entry = Activity::where('subject_type', Expense::class)->where('subject_id', $this->bill->id)->where('event', 'updated')->latest('id')->first();
    expect(ActivityLog::details($entry))->toContain('status: Unpaid → Paid', 'paid_ref: — → SWIFT-77')->not->toContain('updated_at');

    $this->get(ActivityLog::getUrl())->assertOk()->assertSee('Activity log');
    Livewire::test(ActivityLog::class)->assertCanSeeTableRecords([$entry]);

    $this->actingAs($this->paralegal);
    $this->get(ActivityLog::getUrl())->assertForbidden();
});

it('lists paid foreign remittances whose Form 15CA/CB is not filed', function () {
    $paid = ['status' => 'Paid', 'paid_on' => today()->toDateString(), 'paid_ref' => 'T', 'requires_15ca_cb' => true];
    $this->bill->update($paid + ['form_status' => 'Pending']);
    Expense::create(array_replace($this->base, $paid) + ['vendor_invoice_no' => '700001', 'form_status' => ' filed ']);
    $blank = Expense::create(array_replace($this->base, $paid) + ['vendor_invoice_no' => '700002']);
    Expense::create($this->base + ['vendor_invoice_no' => '700003', 'requires_15ca_cb' => true]);

    expect(DashboardService::for($this->accountant)->pendingRemittanceForms()->pluck('id')->sort()->values()->all())->toBe(collect([$this->bill->id, $blank->id])->sort()->values()->all());
    $this->get(Workspace::getUrl())->assertOk()->assertSee('Form 15CA/CB to file')->assertSee('699702')->assertDontSee('700001');
});
