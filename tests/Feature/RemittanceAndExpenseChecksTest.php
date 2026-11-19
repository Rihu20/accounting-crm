<?php

use App\Filament\Pages\ActivityLog;
use App\Filament\Pages\DataChecks;
use App\Filament\Pages\RemittanceForms;
use App\Filament\Pages\RemittanceRegister;
use App\Filament\Pages\Workspace;
use App\Filament\Resources\ExpenseResource\Pages\ManageRecords as ExpensePage;
use App\Models\Expense;
use App\Models\RemittanceBank;
use App\Models\RemittanceForm;
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
    $this->usdBank = RemittanceBank::create(['bank_name' => 'ICICI Bank', 'branch' => 'Noida Sec 18', 'account_no' => 'EEFC-111', 'currency' => 'USD', 'account_type' => 'EEFC']);
    $this->inrBank = RemittanceBank::create(['bank_name' => 'HDFC Bank', 'branch' => 'Delhi', 'account_no' => 'CUR-999', 'currency' => 'INR', 'account_type' => 'Current']);
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
    expect($data)->toMatchArray(['beneficiary_name' => 'Harness, Dickey & Pierce', 'bank_account' => '000123456789', 'swift' => 'MNBDUS33', 'charges' => 'OUR', 'purpose_code' => 'S1004', 'amount' => '820.50', 'debit_bank_id' => $this->usdBank->id, 'charges_bank_id' => $this->inrBank->id])
        ->and($data['info'])->toContain('Invoice 699702', 'Our ref P25104305US');

    $form = $service->prepare($this->bill, $data, $this->accountant);
    $text = docxText($form['content']);

    expect($form['name'])->toBe('A2-Harness-Dickey-Pierce-'.today()->toDateString().'.docx')
        ->and($form['missing'])->toBe([])
        ->and($text)->toContain('FORM A2', 'Harness, Dickey & Pierce', '000123456789', 'MNBDUS33', '820.50', 'USD Eight Hundred Twenty and 50/100 Only', 'S1004', 'OUR þ', 'ABBCA7682H', 'ICICI Bank, Noida Sec 18', 'EEFC-111', 'CUR-999', 'Plot No C-116', today()->format('d.m.Y'))
        ->not->toContain('BEN þ', '${')
        ->and($this->bill->fresh()->a2_prepared_on->isToday())->toBeTrue()
        ->and(Activity::where('description', 'Bank Form A2 prepared')->where('subject_id', $this->bill->id)->exists())->toBeTrue();
});

it('names the blank bank details and always keeps typed ones on the vendor without erasing saved ones', function () {
    $this->vendor->update(['bank_account' => null, 'swift' => null]);
    $service = app(RemittanceFormService::class);
    $form = $service->prepare($this->bill, $service->defaults($this->bill), $this->accountant);
    expect($form['missing'])->toBe(['beneficiary account / IBAN', 'SWIFT code']);

    $service->prepare($this->bill, ['bank_account' => 'DE89370400440532013000', 'swift' => 'COBADEFF', 'bank_address' => 'Frankfurt'] + $service->defaults($this->bill), $this->accountant);
    expect($this->vendor->fresh())->bank_account->toBe('DE89370400440532013000')->swift->toBe('COBADEFF')->bank_address->toBe('Frankfurt');

    $service->prepare($this->bill, ['bank_address' => '', 'swift' => 'COBADEFFXXX'] + $service->defaults($this->bill), $this->accountant);
    expect($this->vendor->fresh())->bank_address->toBe('Frankfurt')->swift->toBe('COBADEFFXXX');
});

it('saves a party paid without a vendor, links the bill and fills its next form from it', function () {
    $service = app(RemittanceFormService::class);
    $first = Expense::create(array_replace($this->base, ['vendor_id' => null, 'payee_name' => 'Wolf Mendez Abogados', 'party_country' => 'Colombia']) + ['vendor_invoice_no' => 'PI-1']);
    $service->prepare($first, ['date' => '2026-06-12', 'address' => 'AK 7 #113-43 Bogota', 'bank_account' => '1040121773', 'bank_name' => 'Helm Bank USA', 'bank_address' => '999 Brickell Avenue Miami', 'swift' => 'HELMUS33'] + $service->defaults($first), $this->accountant);

    $vendor = Vendor::where('name', 'Wolf Mendez Abogados')->sole();
    expect($vendor)->vendor_type->toBe('Foreign')->country->toBe('Colombia')->bank_account->toBe('1040121773')->swift->toBe('HELMUS33')
        ->and($first->fresh()->vendor_id)->toBe($vendor->id);

    $second = Expense::create(array_replace($this->base, ['vendor_id' => null, 'payee_name' => 'WOLF MENDEZ ABOGADOS ', 'party_country' => 'Colombia']) + ['vendor_invoice_no' => 'PI-2']);
    expect($service->defaults($second))->toMatchArray(['beneficiary_name' => 'Wolf Mendez Abogados', 'bank_account' => '1040121773', 'bank_name' => 'Helm Bank USA', 'swift' => 'HELMUS33', 'address' => 'AK 7 #113-43 Bogota']);
    $service->prepare($second, ['date' => '2026-11-19'] + $service->defaults($second), $this->accountant);

    $party = RemittanceFormService::parties()->firstWhere(fn ($p) => $p['form']->beneficiary === 'Wolf Mendez Abogados');
    expect($party['count'])->toBe(2)->and($party['first'])->toBe('2026-06-12')->and($party['form']->data['date'])->toBe('2026-11-19');
    $this->get(RemittanceForms::getUrl())->assertOk()->assertSee('Parties paid by Form A2')->assertSee('Wolf Mendez Abogados')->assertSee('1040121773')->assertSee('19.11.2026')->assertSee('2 forms since 12.06.2026');
});

it('keeps the remittance bank and the charges bank apart and offers the last ones used next time', function () {
    $service = app(RemittanceFormService::class);
    $otherInr = RemittanceBank::create(['bank_name' => 'SBI', 'account_no' => 'SBI-555', 'currency' => 'INR']);
    $service->prepare($this->bill, ['charges_bank_id' => $otherInr->id] + $service->defaults($this->bill), $this->accountant);
    expect($this->bill->fresh())->a2_debit_bank_id->toBe($this->usdBank->id)->a2_charges_bank_id->toBe($otherInr->id);

    $next = Expense::create($this->base + ['vendor_invoice_no' => '699800']);
    expect($service->defaults($next))->toMatchArray(['debit_bank_id' => $this->usdBank->id, 'charges_bank_id' => $otherInr->id]);

    $otherInr->update(['is_active' => false]);
    expect($service->defaults($next)['charges_bank_id'])->toBe($this->inrBank->id)
        ->and(RemittanceFormService::bankOptions('INR'))->not->toHaveKey($otherInr->id)
        ->and(RemittanceFormService::bankOptions('INR', [$otherInr->id]))->toHaveKey($otherInr->id)
        ->and(array_key_first(RemittanceFormService::bankOptions('INR')))->toBe($this->inrBank->id);
});

it('has its own Bank Form A2 page with saved banks and the foreign bills', function () {
    $rupees = Expense::create(array_replace($this->base, ['expense_group' => 'indian', 'currency' => 'INR', 'vendor_invoice_no' => 'R-9', 'category' => 'Rent']));
    $this->get(RemittanceForms::getUrl())->assertOk()->assertSee('Our bank accounts')->assertSee('EEFC-111')->assertSee('CUR-999');

    Livewire::test(RemittanceForms::class)
        ->assertCanSeeTableRecords([$this->bill])->assertCanNotSeeTableRecords([$rupees])
        ->callAction('addBank', ['bank_name' => 'Axis Bank', 'branch' => 'Gurugram', 'account_no' => 'AX-1', 'currency' => 'EUR', 'account_type' => 'EEFC'])
        ->assertHasNoActionErrors()
        ->callAction('editBank', ['account_no' => 'EEFC-112'], ['bank' => $this->usdBank->id])
        ->callAction('removeBank', [], ['bank' => $this->inrBank->id])
        ->callTableAction('bank_form_a2', $this->bill)
        ->assertFileDownloaded('A2-Harness-Dickey-Pierce-'.today()->toDateString().'.docx');

    expect(RemittanceBank::where('account_no', 'AX-1')->value('branch'))->toBe('Gurugram')
        ->and($this->usdBank->fresh()->account_no)->toBe('EEFC-112')
        ->and($this->inrBank->fresh()->is_active)->toBeFalse()
        ->and($this->bill->fresh()->a2_prepared_on)->not->toBeNull();

    $this->actingAs($this->paralegal)->get(RemittanceForms::getUrl())->assertForbidden();
});

it('keeps every prepared form and opens the same Word file again', function () {
    $service = app(RemittanceFormService::class);
    $first = $service->prepare($this->bill, ['date' => '2026-06-15'] + $service->defaults($this->bill), $this->accountant);
    $service->prepare($this->bill, ['charges' => 'SHA'] + $service->defaults($this->bill), $this->accountant);

    $forms = RemittanceForm::orderBy('id')->get();
    expect($forms)->toHaveCount(2)
        ->and($forms[0])->beneficiary->toBe('Harness, Dickey & Pierce')->currency->toBe('USD')->amount->toBe('820.50')->debit_bank_id->toBe($this->usdBank->id)->prepared_by->toBe($this->accountant->id)
        ->and($forms[0]->data)->not->toHaveKey('save_vendor');

    $this->get(RemittanceForms::getUrl())->assertOk()->assertSee('Forms prepared')->assertSee('15.06.2026')->assertSee(route('crm.remittance-form', $forms[0]));
    $again = $this->get(route('crm.remittance-form', $forms[0]))->assertOk()->assertDownload($first['name']);
    expect(docxText($again->streamedContent()))->toBe(docxText($first['content']))->toContain('2026-2027', '15.06.2026', 'OUR þ')
        ->and(docxText($this->get(route('crm.remittance-form', $forms[1]))->streamedContent()))->toContain('SHA þ')->not->toContain('OUR þ');

    $this->actingAs($this->paralegal)->get(route('crm.remittance-form', $forms[0]))->assertForbidden();
});

it('makes a Form A2 straight from the page without a vendor bill', function () {
    $service = app(RemittanceFormService::class);
    expect(RemittanceFormService::partyOptions())->toHaveKey('Harness, Dickey & Pierce')
        ->and($service->partyDetails('harness, dickey & pierce'))->toMatchArray(['beneficiary_name' => 'Harness, Dickey & Pierce', 'bank_account' => '000123456789', 'swift' => 'MNBDUS33']);

    Livewire::test(RemittanceForms::class)
        ->mountAction('new_form_a2')
        ->assertActionDataSet(['currency' => 'USD', 'debit_bank_id' => $this->usdBank->id, 'charges_bank_id' => $this->inrBank->id, 'purpose_code' => 'S1004'])
        ->setActionData(['party' => 'Harness, Dickey & Pierce'])
        ->assertActionDataSet(['beneficiary_name' => 'Harness, Dickey & Pierce', 'bank_account' => '000123456789', 'swift' => 'MNBDUS33'])
        ->setActionData(['currency' => 'EUR', 'amount' => '1500', 'info' => 'Advance for filing'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('A2-Harness-Dickey-Pierce-'.today()->toDateString().'.docx');

    $form = RemittanceForm::sole();
    expect($form)->expense_id->toBeNull()->currency->toBe('EUR')->amount->toBe('1500.00')
        ->and($form->data['info'])->toBe('Advance for filing')
        ->and(docxText(app(RemittanceFormService::class)->render($form)))->toContain('EUR One Thousand Five Hundred Only', '000123456789', 'Advance for filing')
        ->and($this->bill->fresh()->a2_prepared_on)->toBeNull();

    $new = $service->prepare(null, ['currency' => 'GBP', 'amount' => '200', 'beneficiary_name' => 'Marks & Clerk LLP', 'country' => 'United Kingdom', 'bank_account' => 'GB29NWBK60161331926819'] + $service->newFormDefaults(), $this->accountant);
    expect($new['name'])->toBe('A2-Marks-Clerk-LLP-'.today()->toDateString().'.docx')
        ->and(Vendor::where('name', 'Marks & Clerk LLP')->sole())->bank_account->toBe('GB29NWBK60161331926819')->default_currency->toBe('GBP')->country->toBe('United Kingdom');
    expect(fn () => $service->prepare(null, ['currency' => 'INR', 'amount' => '5', 'beneficiary_name' => 'X'] + $service->newFormDefaults(), $this->accountant))->toThrow(ValidationException::class);
    expect(fn () => $service->prepare(null, ['amount' => '5', 'beneficiary_name' => 'X'] + $service->newFormDefaults(), $this->paralegal))->toThrow(HttpException::class);
});

it('pays several bills with one form and fills the rupee equivalent', function () {
    $service = app(RemittanceFormService::class);
    $second = Expense::create(array_replace($this->base, ['amount' => '179.50', 'invoice_date' => today()->subDay()]) + ['vendor_invoice_no' => '699800']);
    $euro = Expense::create(array_replace($this->base, ['currency' => 'EUR']) + ['vendor_invoice_no' => 'E-1']);
    expect(RemittanceFormService::billOptions('USD'))->toHaveKeys([$this->bill->id, $second->id])->not->toHaveKey($euro->id);

    $summary = RemittanceFormService::billsSummary([$this->bill->id, $second->id]);
    expect($summary['amount'])->toBe('1000.00')->and($summary['info'])->toContain('Invoice 699800', 'Invoice 699702 dated', 'our ref P25104305US');

    $made = $service->prepare(null, ['expense_ids' => [$this->bill->id, $second->id], 'amount' => $summary['amount'], 'info' => $summary['info'], 'exchange_rate' => '83.25', 'party' => 'x'] + $service->partyDetails('Harness, Dickey & Pierce') + $service->newFormDefaults(), $this->accountant);
    expect($made['form']->expenses()->pluck('expenses.id')->sort()->values()->all())->toBe(collect([$this->bill->id, $second->id])->sort()->values()->all())
        ->and($this->bill->fresh()->a2_prepared_on)->not->toBeNull()->and($second->fresh()->a2_prepared_on)->not->toBeNull()
        ->and(docxText($made['content']))->toContain('83,250.00 (at 83.25)', 'USD One Thousand Only', 'Invoice 699800')
        ->and(RemittanceFormService::billOptions('USD'))->not->toHaveKey($this->bill->id);
    expect(docxText($service->prepare($euro, $service->defaults($euro), $this->accountant)['content']))->toContain('Equivalent to Rupees. _____');

    expect(fn () => $service->prepare(null, ['expense_ids' => [$euro->id], 'amount' => '1', 'beneficiary_name' => 'X'] + $service->newFormDefaults(), $this->accountant))
        ->toThrow(ValidationException::class, 'is not a USD vendor bill');
});

it('asks for an IBAN where the bank needs one, checks SWIFT codes and knows the purpose codes', function () {
    $service = app(RemittanceFormService::class);
    $try = fn (array $data) => $service->prepare(null, $data + ['amount' => '10', 'beneficiary_name' => 'Kanzlei GmbH'] + $service->newFormDefaults('EUR'), $this->accountant);

    expect(fn () => $try(['country' => 'Germany', 'bank_account' => '123456789']))->toThrow(ValidationException::class, 'IBAN is mandatory for Germany');
    expect(fn () => $try(['country' => 'germany', 'bank_account' => 'DE89 3704 0044 0532 0130 01']))->toThrow(ValidationException::class, 'IBAN');
    expect($try(['country' => 'Germany', 'bank_account' => 'DE89 3704 0044 0532 0130 00', 'swift' => 'cobadeff'])['missing'])->not->toContain('SWIFT code')
        ->and($try(['country' => 'United States', 'bank_account' => '123', 'swift' => '021000021'])['form']->exists)->toBeTrue();
    expect(fn () => $try(['country' => 'Japan', 'swift' => 'MIZUHO']))->toThrow(ValidationException::class, 'not a valid SWIFT');
    expect(RemittanceFormService::isIban('GB29NWBK60161331926819'))->toBeTrue()
        ->and(RemittanceFormService::PURPOSE_CODES['S1004'])->toBe('Legal Services');
});

it('tracks a form from the bank to the remittance, marks the bills paid and files 15CA/CB', function () {
    $service = app(RemittanceFormService::class);
    $form = $service->prepare($this->bill, $service->defaults($this->bill), $this->accountant)['form'];

    $service->markSubmitted($form, today()->toDateString(), $this->accountant);
    expect($form->fresh())->status->toBe('Submitted')->submitted_on->isToday()->toBeTrue();

    $paid = $service->markRemitted($form, ['remitted_on' => today()->toDateString(), 'bank_reference' => 'DB-SWIFT-778', 'exchange_rate' => '83.10', 'inr_debited' => '68183.55', 'bank_charges_inr' => '1180'], $this->accountant);
    expect($paid)->toBe(1)
        ->and($form->fresh())->status->toBe('Remitted')->inr_debited->toBe('68183.55')->bank_charges_inr->toBe('1180.00')
        ->and($this->bill->fresh())->status->toBe('Paid')->paid_ref->toBe('DB-SWIFT-778')->payment_bank_name->toBe('ICICI Bank');
    expect(fn () => $service->markRemitted($form->fresh(), ['remitted_on' => today()->toDateString(), 'bank_reference' => 'X'], $this->accountant))->toThrow(HttpException::class);
    expect(fn () => $service->update($form->fresh(), $service->formData($form->fresh()), $this->accountant))->toThrow(HttpException::class, 'Make a copy');

    $this->bill->update(['requires_15ca_cb' => true]);
    expect(DashboardService::for($this->accountant)->pendingRemittanceForms()->pluck('id'))->toContain($this->bill->id);
    $service->save15caCb($form->fresh(), ['form_15ca_ack' => '123456789012345', 'form_15ca_date' => today()->toDateString(), 'form_15cb_no' => 'UDIN-26-1'], $this->accountant);
    expect($this->bill->fresh()->form_status)->toBe('Filed')
        ->and(DashboardService::for($this->accountant)->pendingRemittanceForms()->pluck('id'))->not->toContain($this->bill->id);
});

it('edits, copies and cancels forms, freeing the bills of a cancelled one', function () {
    $service = app(RemittanceFormService::class);
    $form = $service->prepare($this->bill, $service->defaults($this->bill), $this->accountant)['form'];

    $edited = $service->update($form, ['amount' => '800', 'beneficiary_name' => 'Harness Dickey & Pierce PLC'] + $service->formData($form), $this->accountant);
    expect($edited['form'])->amount->toBe('800.00')->beneficiary->toBe('Harness Dickey & Pierce PLC')->id->toBe($form->id)
        ->and(RemittanceForm::count())->toBe(1);

    $service->cancel($form->fresh(), 'Wrong amount', $this->accountant);
    expect($form->fresh())->status->toBe('Cancelled')->cancelled_reason->toBe('Wrong amount')
        ->and($this->bill->fresh()->a2_prepared_on)->toBeNull()
        ->and(RemittanceFormService::billOptions('USD'))->toHaveKey($this->bill->id);
    expect(fn () => $service->cancel($form->fresh(), 'again', $this->accountant))->toThrow(HttpException::class);
});

it('has a remittance register with actions and an Excel export', function () {
    $service = app(RemittanceFormService::class);
    $form = $service->prepare($this->bill, ['exchange_rate' => '83'] + $service->defaults($this->bill), $this->accountant)['form'];

    $this->get(RemittanceRegister::getUrl())->assertOk()->assertSee('Remittance register')->assertSee('Harness, Dickey &amp; Pierce', false);
    Livewire::test(RemittanceRegister::class)
        ->assertCanSeeTableRecords([$form])
        ->callTableAction('submitted', $form, ['date' => today()->toDateString()])
        ->callTableAction('form_15ca', $form, ['form_15ca_ack' => 'ACK-1'])
        ->callTableAction('remitted', $form, ['remitted_on' => today()->toDateString(), 'bank_reference' => 'REF-9', 'inr_debited' => '68101.50'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('copy_form_a2', $form)
        ->assertHasNoTableActionErrors();

    expect($form->fresh())->status->toBe('Remitted')->form_15ca_ack->toBe('ACK-1')
        ->and(RemittanceForm::count())->toBe(2)->and(RemittanceForm::latest('id')->first()->expenses()->count())->toBe(0);

    $rows = Livewire::test(RemittanceRegister::class)->instance()->exportRows();
    expect($rows[0])->toContain('Bank reference', '15CA acknowledgement')
        ->and(collect($rows)->firstWhere(fn ($r) => $r[1] === 'Remitted'))->toContain('REF-9', 'ACK-1', 820.5, 83.0, 68101.5, '699702');
    Livewire::test(RemittanceRegister::class)->callAction('export')->assertFileDownloaded('Remittance-register-'.today()->toDateString().'.xlsx');

    $this->actingAs($this->paralegal)->get(RemittanceRegister::getUrl())->assertForbidden();
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
