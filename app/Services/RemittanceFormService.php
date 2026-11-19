<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RemittanceBank;
use App\Models\RemittanceForm;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\Settings as WordSettings;
use PhpOffice\PhpWord\TemplateProcessor;

/** The bank's Form A2 (application for remittance abroad), filled from a foreign vendor bill. Nothing is sent to the bank. */
class RemittanceFormService
{
    public const DEFAULTS = [
        'a2_purpose' => 'Official and Professional Payment for Patent Filing, Technical Translation and Prosecution Matters',
        'a2_purpose_code' => 'S1004',
        'a2_purpose_desc' => 'Legal Services',
        'a2_charges' => 'OUR',
        'a2_source_funds' => 'Business Income',
    ];

    /** Vendor bank details that the form can save back to the vendor master. */
    public const VENDOR_FIELDS = ['address', 'bank_account', 'bank_name', 'bank_address', 'swift', 'corr_bank_name', 'corr_bank_address', 'corr_bank_swift'];

    /** Who may make a Form A2 at all (with or without a bill). */
    public static function canPrepareAny(User $user): bool
    {
        return $user->allowed('accounting.expenses.pay') || $user->allowed('accounting.expenses.edit_all');
    }

    public static function canPrepare(Expense $expense, User $user): bool
    {
        return $expense->expense_type === 'vendor' && $expense->currency !== 'INR' && self::canPrepareAny($user);
    }

    /** Currencies a Form A2 can be made in (foreign only). @return array<string, string> */
    public static function currencies(): array
    {
        return collect(config('crm.expense_currencies'))->reject(fn ($c) => $c === 'INR')->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /** @return array<string, mixed> */
    public function defaults(Expense $expense): array
    {
        $expense->loadMissing('vendor');
        $info = collect([
            $expense->vendor_invoice_no ? 'Invoice '.$expense->vendor_invoice_no.($expense->invoice_date ? ' dated '.$expense->invoice_date->format('d.m.Y') : '') : null,
            $expense->our_ref ? 'Our ref '.$expense->our_ref : null,
        ])->filter()->implode('; ');
        $party = $this->partyDetails($expense->vendor?->name ?: (string) $expense->payee_name, $expense->vendor);

        return ['amount' => (string) $expense->amount, 'country' => $expense->party_country ?: $party['country'], 'info' => $info]
            + $party + $this->formDefaults($expense, $expense->currency);
    }

    /** A form made straight from the Bank Form A2 page, without a bill. @return array<string, mixed> */
    public function newFormDefaults(string $currency = 'USD'): array
    {
        return ['currency' => $currency, 'party' => null, 'beneficiary_name' => null, 'amount' => null, 'info' => null] + $this->formDefaults(null, $currency);
    }

    /**
     * A party's saved details: the vendor master first, then what was typed on its last form.
     *
     * @return array<string, mixed>
     */
    public function partyDetails(string $name, ?Vendor $vendor = null): array
    {
        $vendor ??= self::vendorNamed($name);
        $last = self::lastFormFor($vendor?->name ?: $name)?->data ?? [];
        $pick = fn (string $field) => $vendor?->{$field} ?: ($last[$field] ?? null);

        return ['beneficiary_name' => $vendor?->name ?: (($last['beneficiary_name'] ?? null) ?: ($name ?: null)), 'country' => $vendor?->country ?: ($last['country'] ?? null)]
            + collect(self::VENDOR_FIELDS)->mapWithKeys(fn ($field) => [$field => $pick($field)])->all();
    }

    /**
     * Saved parties to pick from: foreign vendors and everyone a form was made for.
     *
     * @return array<string, string>
     */
    public static function partyOptions(): array
    {
        return Vendor::where('vendor_type', 'Foreign')->pluck('name')
            ->merge(RemittanceForm::distinct()->pluck('beneficiary'))
            ->map(fn ($name) => trim((string) $name))->filter()->unique(fn ($name) => mb_strtolower($name))
            ->sortBy(fn ($name) => mb_strtolower($name))->mapWithKeys(fn ($name) => [$name => $name])->all();
    }

    /** @return array<string, mixed> */
    private function formDefaults(?Expense $expense, string $currency): array
    {
        return [
            'date' => today()->toDateString(),
            'debit_bank_id' => $this->rememberedBank($expense, 'a2_debit_bank_id', $currency),
            'charges_bank_id' => $this->rememberedBank($expense, 'a2_charges_bank_id', 'INR'),
            'purpose' => self::setting('a2_purpose'),
            'charges' => self::setting('a2_charges'),
            'purpose_code' => self::setting('a2_purpose_code'),
            'purpose_desc' => self::setting('a2_purpose_desc'),
            'source_funds' => self::setting('a2_source_funds'),
        ];
    }

    public static function vendorNamed(string $name): ?Vendor
    {
        $name = mb_strtolower(trim($name));

        return $name === '' ? null : Vendor::whereRaw('lower(trim(name)) = ?', [$name])->first();
    }

    public static function lastFormFor(string $beneficiary): ?RemittanceForm
    {
        $name = mb_strtolower(trim($beneficiary));

        return $name === '' ? null : RemittanceForm::whereRaw('lower(trim(beneficiary)) = ?', [$name])->latest('id')->first();
    }

    /**
     * Every party paid with a Form A2: its latest details, when the last form was made and how many forms.
     *
     * @return Collection<int, array{form: RemittanceForm, count: int, first: string}>
     */
    public static function parties(): Collection
    {
        return RemittanceForm::orderByDesc('id')->get()
            ->groupBy(fn (RemittanceForm $form) => mb_strtolower(trim($form->beneficiary)))
            ->map(fn (Collection $forms) => ['form' => $forms->first(), 'count' => $forms->count(), 'first' => (string) $forms->min(fn (RemittanceForm $f) => $f->data['date'] ?? $f->created_at->toDateString())])
            ->sortBy(fn (array $party) => mb_strtolower($party['form']->beneficiary))->values();
    }

    /**
     * Saved banks, those in the given currency first. A bank used on this bill stays listed even if it was removed later.
     *
     * @return array<int, string>
     */
    public static function bankOptions(?string $currency = null, array $keep = []): array
    {
        return RemittanceBank::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_filter($keep)))
            ->get()->sortBy(fn (RemittanceBank $b) => [$b->currency === $currency ? 0 : 1, $b->bank_name, $b->currency])
            ->mapWithKeys(fn (RemittanceBank $b) => [$b->id => $b->label()])->all();
    }

    /** This bill's earlier choice, else the bank used on the last Form A2, else the first saved bank in the currency. */
    private function rememberedBank(?Expense $expense, string $column, string $currency): ?int
    {
        $active = fn ($id): ?int => $id && RemittanceBank::whereKey($id)->where('is_active', true)->exists() ? (int) $id : null;
        $lastForm = RemittanceForm::whereNotNull($column === 'a2_debit_bank_id' ? 'debit_bank_id' : 'charges_bank_id')->latest('id')->value($column === 'a2_debit_bank_id' ? 'debit_bank_id' : 'charges_bank_id');

        return $active($expense?->{$column})
            ?? $active($lastForm)
            ?? $active(Expense::whereNotNull($column)->orderByDesc('a2_prepared_on')->orderByDesc('id')->value($column))
            ?? RemittanceBank::where('is_active', true)->where('currency', $currency)->orderBy('bank_name')->value('id');
    }

    /**
     * Builds the Word file, records that the form was prepared and, when asked, keeps the beneficiary details on the vendor.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, content: string, missing: array<int, string>}
     */
    public function prepare(?Expense $expense, array $data, User $actor): array
    {
        abort_unless($expense ? self::canPrepare($expense, $actor) : self::canPrepareAny($actor), 403);
        if ($expense) {
            $data['currency'] = $expense->currency;
        }
        $data = Validator::make($data, [
            'currency' => ['required', 'in:'.implode(',', array_keys(self::currencies()))],
            'date' => 'required|date',
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0.01'],
            'debit_bank_id' => 'nullable|integer|exists:remittance_banks,id',
            'charges_bank_id' => 'nullable|integer|exists:remittance_banks,id',
            'beneficiary_name' => 'required|string|max:255',
            'charges' => 'required|in:BEN,OUR,SHA',
        ] + collect(['country', 'bank_account', 'bank_name', 'swift', 'corr_bank_name', 'corr_bank_swift', 'purpose_code', 'purpose_desc', 'source_funds'])->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:255'])->all()
          + collect(['address', 'bank_address', 'corr_bank_address', 'purpose', 'info'])->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:2000'])->all())->validate();

        $debit = isset($data['debit_bank_id']) ? RemittanceBank::find($data['debit_bank_id']) : null;
        $charges = isset($data['charges_bank_id']) ? RemittanceBank::find($data['charges_bank_id']) : null;
        $currency = $data['currency'];
        $content = $this->document($currency, $data, $debit, $charges);
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $data['beneficiary_name']), '-');
        $name = 'A2-'.substr($slug, 0, 40).'-'.$data['date'].'.docx';

        DB::transaction(function () use ($expense, $data, $actor, $name, $currency): void {
            $form = RemittanceForm::create([
                'expense_id' => $expense?->id, 'prepared_by' => $actor->id, 'beneficiary' => $data['beneficiary_name'], 'currency' => $currency, 'amount' => $data['amount'],
                'debit_bank_id' => $data['debit_bank_id'] ?? null, 'charges_bank_id' => $data['charges_bank_id'] ?? null, 'file_name' => $name, 'data' => $data,
            ]);
            $vendor = $this->keepPartyDetails($expense, $data, $currency);
            $expense?->forceFill(['a2_prepared_on' => today()->toDateString(), 'a2_debit_bank_id' => $data['debit_bank_id'] ?? null, 'a2_charges_bank_id' => $data['charges_bank_id'] ?? null, 'vendor_id' => $expense->vendor_id ?? $vendor->id])->saveQuietly();
            activity()->causedBy($actor)->performedOn($expense ?? $form)->withProperties(['beneficiary' => $data['beneficiary_name'], 'amount' => $data['amount'].' '.$currency])->log('Bank Form A2 prepared');
        });

        $missing = collect(['debit_bank_id' => 'bank account for the remittance', 'charges_bank_id' => 'bank account for charges', 'bank_account' => 'beneficiary account / IBAN', 'bank_name' => 'beneficiary bank', 'swift' => 'SWIFT code'])->filter(fn ($label, $key) => blank($data[$key] ?? null))->values()->all();

        return ['name' => $name, 'content' => $content, 'missing' => $missing];
    }

    /**
     * The beneficiary's address and bank details always go to the vendor master, so the next form starts filled.
     * The bill's vendor is used, else a vendor with the same name, else a new foreign vendor. Blank fields never erase saved ones.
     *
     * @param  array<string, mixed>  $data
     */
    private function keepPartyDetails(?Expense $expense, array $data, string $currency): Vendor
    {
        $vendor = $expense?->vendor ?? self::vendorNamed($data['beneficiary_name'])
            ?? Vendor::create(['name' => trim($data['beneficiary_name']), 'vendor_type' => 'Foreign', 'country' => ($data['country'] ?? null) ?: ($expense?->party_country ?: 'Not set'), 'default_currency' => $currency]);
        $details = collect(self::VENDOR_FIELDS)->mapWithKeys(fn ($field) => [$field => trim((string) ($data[$field] ?? ''))])->filter(fn ($value) => $value !== '');
        $vendor->update($details->all());

        return $vendor;
    }

    /** The same Word file again, from the values kept when the form was prepared. */
    public function render(RemittanceForm $form): string
    {
        return $this->document($form->currency, $form->data, $form->debitBank, $form->chargesBank);
    }

    public static function companyPan(): string
    {
        return (string) (Setting::get('company_pan') ?: substr((string) Setting::get('company_gstin', ''), 2, 10));
    }

    public static function words(string $amount, string $currency): string
    {
        $parts = explode('.', Money::round($amount));
        $spell = ucwords(str_replace(['-', ' and '], [' ', ' '], (new \NumberFormatter('en', \NumberFormatter::SPELLOUT))->format((int) $parts[0])));

        return $currency.' '.$spell.((int) $parts[1] ? ' and '.$parts[1].'/100' : '').' Only';
    }

    private static function setting(string $key): string
    {
        return (string) (Setting::get($key) ?: self::DEFAULTS[$key]);
    }

    /**
     * Fills the bank's own Form A2 (docs/Form_A2_Template.docx), so the layout is exactly the bank's.
     *
     * @param  array<string, mixed>  $data
     */
    private function document(string $currency, array $data, ?RemittanceBank $debit, ?RemittanceBank $charges): string
    {
        $date = Carbon::parse($data['date']);
        $start = $date->month >= 4 ? $date->year : $date->year - 1;
        $text = fn (string $key): string => trim((string) ($data[$key] ?? ''));
        $values = [
            'currency' => $currency,
            'amount' => number_format((float) $data['amount'], $currency === 'JPY' ? 0 : 2),
            'amount_words' => self::words((string) $data['amount'], $currency),
            'company' => (string) Setting::get('company_name', ''),
            'pan' => self::companyPan(),
            'address' => (string) Setting::get('company_address', ''),
            'ad_bank' => $debit ? $debit->bank_name.($debit->branch ? ', '.$debit->branch : '') : '',
            'debit_account' => (string) ($debit?->account_no ?? ''),
            'charges_account' => (string) ($charges?->account_no ?? ''),
            'b_name' => $text('beneficiary_name'),
            'b_address' => $text('address'),
            'b_account' => $text('bank_account'),
            'b_bank' => $text('bank_name'),
            'b_bank_address' => $text('bank_address'),
            'b_swift' => $text('swift'),
            'purpose' => $text('purpose'),
            'c_name' => $text('corr_bank_name'),
            'c_address' => $text('corr_bank_address'),
            'c_swift' => $text('corr_bank_swift'),
            'info' => $text('info'),
            'purpose_code' => $text('purpose_code'),
            'purpose_desc' => $text('purpose_desc'),
            'country' => $text('country'),
            'source_funds' => $text('source_funds'),
            'fy' => $start.'-'.($start + 1),
            'date' => $date->format('d.m.Y'),
        ];
        // The tick is a Wingdings character: "þ" draws the ticked box.
        foreach (['BEN', 'OUR', 'SHA'] as $option) {
            $values['tick_'.strtolower($option)] = $data['charges'] === $option ? 'þ' : '';
        }

        $escaping = WordSettings::isOutputEscapingEnabled();
        WordSettings::setOutputEscapingEnabled(true);
        $path = tempnam(sys_get_temp_dir(), 'a2');
        try {
            $template = new TemplateProcessor(base_path('docs/Form_A2_Template.docx'));
            $template->setValues($values);
            $template->saveAs($path);

            return (string) file_get_contents($path);
        } finally {
            WordSettings::setOutputEscapingEnabled($escaping);
            @unlink($path);
        }
    }
}
