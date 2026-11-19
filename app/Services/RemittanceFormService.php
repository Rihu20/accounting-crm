<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RemittanceBank;
use App\Models\RemittanceForm;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        return ['currency' => $currency, 'party' => null, 'expense_ids' => [], 'beneficiary_name' => null, 'amount' => null, 'exchange_rate' => null, 'info' => null] + $this->formDefaults(null, $currency);
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

    /** Countries where the bank needs an IBAN (the note on the bank's form: UAE, European countries and the United Kingdom). */
    public const IBAN_COUNTRIES = ['united arab emirates', 'uae', 'u.a.e.', 'united kingdom', 'uk', 'u.k.', 'great britain', 'england', 'scotland', 'wales', 'northern ireland', 'austria', 'belgium', 'bulgaria', 'croatia', 'cyprus', 'czech republic', 'czechia', 'denmark', 'estonia', 'finland', 'france', 'germany', 'greece', 'hungary', 'ireland', 'italy', 'latvia', 'lithuania', 'luxembourg', 'malta', 'netherlands', 'the netherlands', 'poland', 'portugal', 'romania', 'slovakia', 'slovenia', 'spain', 'sweden', 'norway', 'iceland', 'liechtenstein', 'switzerland', 'monaco', 'san marino'];

    /** RBI purpose codes for services (group 10, other business services) offered on the form. */
    public const PURPOSE_CODES = [
        'S1004' => 'Legal Services',
        'S1005' => 'Accounting, auditing, book keeping and tax consulting services',
        'S1006' => 'Business and management consultancy and public relations services',
        'S1007' => 'Advertising, trade fair, market research and public opinion polling services',
        'S1008' => 'Research and Development services',
        'S1009' => 'Architectural, engineering and other technical services',
    ];

    /**
     * Builds the Word file, records the form against its bills and keeps the beneficiary details on the vendor.
     *
     * @param  array<string, mixed>  $data  may hold expense_ids: further bills paid by the same remittance
     * @return array{name: string, content: string, missing: array<int, string>, form: RemittanceForm}
     */
    public function prepare(?Expense $expense, array $data, User $actor): array
    {
        abort_unless($expense ? self::canPrepare($expense, $actor) : self::canPrepareAny($actor), 403);
        if ($expense) {
            $data['currency'] = $expense->currency;
        }
        $data = $this->validateForm($data);
        $bills = $this->bills($expense, $data, $actor);
        $name = $this->fileName($data);

        $form = DB::transaction(function () use ($expense, $data, $actor, $name, $bills): RemittanceForm {
            $form = RemittanceForm::create([
                'expense_id' => $bills->first()?->id, 'prepared_by' => $actor->id, 'status' => 'Prepared', 'file_name' => $name,
            ] + $this->formColumns($data));
            $form->expenses()->sync($bills->pluck('id'));
            $this->linkBills($form, $bills, $this->keepPartyDetails($expense ?? $bills->first(), $data, $data['currency']));
            activity()->causedBy($actor)->performedOn($expense ?? $form)->withProperties(['beneficiary' => $data['beneficiary_name'], 'amount' => $data['amount'].' '.$data['currency'], 'bills' => $bills->count()])->log('Bank Form A2 prepared');

            return $form;
        });

        return ['name' => $name, 'content' => $this->render($form), 'missing' => $this->missing($data), 'form' => $form];
    }

    /**
     * Corrects a form that is not yet remitted or cancelled; the bills can be changed too.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, content: string, missing: array<int, string>, form: RemittanceForm}
     */
    public function update(RemittanceForm $form, array $data, User $actor): array
    {
        abort_unless(self::canPrepareAny($actor), 403);
        abort_unless($form->isOpen(), 422, 'Only a form that is not yet remitted or cancelled can be edited. Make a copy instead.');
        $data = $this->validateForm($data);
        $bills = $this->bills(null, $data, $actor);
        $name = $this->fileName($data);

        DB::transaction(function () use ($form, $data, $actor, $name, $bills): void {
            $old = $form->expenses()->pluck('expenses.id');
            $form->update(['expense_id' => $bills->first()?->id, 'file_name' => $name] + $this->formColumns($data));
            $form->expenses()->sync($bills->pluck('id'));
            $this->releaseBills($old->diff($bills->pluck('id')));
            $this->linkBills($form, $bills, $this->keepPartyDetails($bills->first(), $data, $data['currency']));
            activity()->causedBy($actor)->performedOn($form)->withProperties(['beneficiary' => $data['beneficiary_name'], 'amount' => $data['amount'].' '.$data['currency']])->log('Bank Form A2 edited');
        });
        $form->refresh();

        return ['name' => $name, 'content' => $this->render($form), 'missing' => $this->missing($data), 'form' => $form];
    }

    /** The form as it can be opened again for editing or copying. @return array<string, mixed> */
    public function formData(RemittanceForm $form): array
    {
        return ['expense_ids' => $form->expenses()->pluck('expenses.id')->map(fn ($id) => (string) $id)->all(), 'party' => null] + $form->data;
    }

    public function markSubmitted(RemittanceForm $form, string $date, User $actor): void
    {
        abort_unless(self::canPrepareAny($actor), 403);
        abort_unless($form->status === 'Prepared', 422, 'Only a prepared form can be marked as handed to the bank.');
        Validator::make(['date' => $date], ['date' => 'required|date|before_or_equal:today'])->validate();
        $form->update(['status' => 'Submitted', 'submitted_on' => $date]);
        activity()->causedBy($actor)->performedOn($form)->log('Form A2 handed to the bank');
    }

    /**
     * The bank has sent the money: keep its reference, rate and charges, and mark the bills paid.
     *
     * @param  array<string, mixed>  $data
     * @return int bills marked paid
     */
    public function markRemitted(RemittanceForm $form, array $data, User $actor): int
    {
        abort_unless($actor->allowed('accounting.expenses.pay'), 403);
        abort_unless($form->isOpen(), 422, 'This form is already remitted or cancelled.');
        $data = Validator::make($data, [
            'remitted_on' => 'required|date|before_or_equal:today',
            'bank_reference' => 'required|string|max:255',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'inr_debited' => 'nullable|numeric|min:0',
            'bank_charges_inr' => 'nullable|numeric|min:0',
            'advice_path' => 'nullable|string|max:500',
        ])->validate();

        return DB::transaction(function () use ($form, $data, $actor): int {
            $form->update(['status' => 'Remitted', 'submitted_on' => $form->submitted_on ?? $data['remitted_on']] + $data);
            $paid = 0;
            foreach ($form->expenses()->where('status', 'Unpaid')->where('expense_type', 'vendor')->get() as $bill) {
                app(ExpenseService::class)->paid($bill, ['paid_on' => $data['remitted_on'], 'paid_ref' => $data['bank_reference'], 'payment_bank_name' => $form->debitBank?->bank_name], $actor);
                $paid++;
            }
            activity()->causedBy($actor)->performedOn($form)->withProperties(['reference' => $data['bank_reference'], 'bills_paid' => $paid])->log('Remittance sent by the bank');

            return $paid;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function save15caCb(RemittanceForm $form, array $data, User $actor): void
    {
        abort_unless(self::canPrepareAny($actor), 403);
        $data = Validator::make($data, [
            'form_15ca_ack' => 'nullable|string|max:255',
            'form_15ca_date' => 'nullable|date|before_or_equal:today',
            'form_15cb_no' => 'nullable|string|max:255',
            'form_15cb_date' => 'nullable|date|before_or_equal:today',
        ])->validate();
        DB::transaction(function () use ($form, $data, $actor): void {
            $form->update($data);
            if (filled($data['form_15ca_ack'] ?? null)) {
                $form->expenses()->get()->each(fn (Expense $bill) => $bill->update(['requires_15ca_cb' => true, 'form_status' => 'Filed']));
            }
            activity()->causedBy($actor)->performedOn($form)->withProperties(array_filter($data))->log('Form 15CA/CB details saved');
        });
    }

    public function cancel(RemittanceForm $form, string $reason, User $actor): void
    {
        abort_unless(self::canPrepareAny($actor), 403);
        abort_unless($form->isOpen(), 422, 'A remitted or cancelled form cannot be cancelled.');
        abort_if(trim($reason) === '', 422, 'Give a reason.');
        DB::transaction(function () use ($form, $reason, $actor): void {
            $form->update(['status' => 'Cancelled', 'cancelled_reason' => trim($reason)]);
            $this->releaseBills($form->expenses()->pluck('expenses.id'));
            activity()->causedBy($actor)->performedOn($form)->withProperties(['reason' => trim($reason)])->log('Form A2 cancelled');
        });
    }

    /**
     * Foreign bills that a new form may pay: not paid yet, in the currency, and not already on an open form (except this one).
     *
     * @return array<int, string>
     */
    public static function billOptions(?string $currency, ?int $exceptForm = null, array $keep = []): array
    {
        return Expense::with('vendor')->where('expense_type', 'vendor')->where('currency', '!=', 'INR')
            ->when($currency, fn ($q) => $q->where('currency', $currency))
            ->where(fn ($q) => $q->where('status', 'Unpaid')->orWhereIn('id', array_filter($keep)))
            ->whereDoesntHave('remittanceForms', fn ($q) => $q->whereIn('status', ['Prepared', 'Submitted'])->when($exceptForm, fn ($q) => $q->whereKeyNot($exceptForm)))
            ->orderBy('payee_name')->orderBy('invoice_date')->get()
            ->mapWithKeys(fn (Expense $e) => [$e->id => ($e->payee_name ?: $e->vendor?->name).' · '.($e->vendor_invoice_no ?: 'no invoice no').' · '.$e->invoice_date?->format('d.m.Y').' · '.Money::format((string) $e->amount, $e->currency)])->all();
    }

    /**
     * Amount and additional information worked out from the chosen bills.
     *
     * @param  array<int, int|string>  $ids
     * @return array{amount: string, info: string, beneficiary: ?string}
     */
    public static function billsSummary(array $ids): array
    {
        $bills = Expense::with('vendor')->whereKey($ids)->orderBy('invoice_date')->get();
        $total = $bills->reduce(fn (string $sum, Expense $e) => bcadd($sum, (string) $e->amount, 2), '0.00');
        $info = $bills->map(fn (Expense $e) => 'Invoice '.($e->vendor_invoice_no ?: '-').($e->invoice_date ? ' dated '.$e->invoice_date->format('d.m.Y') : '').($e->our_ref ? ' (our ref '.$e->our_ref.')' : ''))->implode('; ');

        return ['amount' => $total, 'info' => $info, 'beneficiary' => $bills->first()?->vendor?->name ?: $bills->first()?->payee_name];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateForm(array $data): array
    {
        $text = fn (array $keys, int $max) => collect($keys)->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:'.$max])->all();
        $validator = Validator::make($data, [
            'currency' => ['required', 'in:'.implode(',', array_keys(self::currencies()))],
            'date' => 'required|date',
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0.01'],
            'exchange_rate' => 'nullable|numeric|gt:0',
            'debit_bank_id' => 'nullable|integer|exists:remittance_banks,id',
            'charges_bank_id' => 'nullable|integer|exists:remittance_banks,id',
            'beneficiary_name' => 'required|string|max:255',
            'charges' => 'required|in:BEN,OUR,SHA',
            'expense_ids' => 'nullable|array',
            'expense_ids.*' => 'integer|exists:expenses,id',
        ] + $text(['country', 'bank_account', 'bank_name', 'swift', 'corr_bank_name', 'corr_bank_swift', 'purpose_code', 'purpose_desc', 'source_funds'], 255)
          + $text(['address', 'bank_address', 'corr_bank_address', 'purpose', 'info'], 2000));
        $validator->after(function ($v) use ($data): void {
            $country = mb_strtolower(trim((string) ($data['country'] ?? '')));
            if (in_array($country, self::IBAN_COUNTRIES, true) && ! self::isIban((string) ($data['bank_account'] ?? ''))) {
                $v->errors()->add('bank_account', 'A valid IBAN is mandatory for '.trim((string) $data['country']).' (it starts with the country letters, for example GB29 NWBK 6016 1331 9268 19).');
            }
            foreach (['swift' => 'SWIFT', 'corr_bank_swift' => 'Correspondent bank SWIFT'] as $field => $label) {
                $code = strtoupper(str_replace(' ', '', (string) ($data[$field] ?? '')));
                if (preg_match('/[A-Z]/', $code) && ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $code)) {
                    $v->errors()->add($field, $label.' '.$code.' is not a valid SWIFT/BIC code (8 or 11 characters, such as MNBDUS33). An ABA, sort code or BLZ is digits only.');
                }
            }
        });

        return $validator->validate();
    }

    public static function isIban(string $value): bool
    {
        $iban = strtoupper(str_replace(' ', '', $value));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $digits = preg_replace_callback('/[A-Z]/', fn ($m) => (string) (ord($m[0]) - 55), substr($iban, 4).substr($iban, 0, 4));

        return bcmod($digits, '97') === '1';
    }

    /**
     * The bill this form was opened from plus any chosen in the form, all in the form's currency.
     *
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Database\Eloquent\Collection<int, Expense>
     */
    private function bills(?Expense $expense, array $data, User $actor)
    {
        $ids = collect($data['expense_ids'] ?? [])->push($expense?->id)->filter()->unique()->values();
        $bills = Expense::with('vendor')->whereKey($ids)->get()->sortBy(fn (Expense $e) => $e->id === $expense?->id ? 0 : 1)->values();
        foreach ($bills as $bill) {
            if (! self::canPrepare($bill, $actor) || $bill->currency !== $data['currency']) {
                throw ValidationException::withMessages(['expense_ids' => 'Bill '.($bill->vendor_invoice_no ?: '#'.$bill->id).' is not a '.$data['currency'].' vendor bill.']);
            }
        }

        return $bills;
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function formColumns(array $data): array
    {
        return ['beneficiary' => $data['beneficiary_name'], 'currency' => $data['currency'], 'amount' => $data['amount'], 'exchange_rate' => $data['exchange_rate'] ?? null,
            'debit_bank_id' => $data['debit_bank_id'] ?? null, 'charges_bank_id' => $data['charges_bank_id'] ?? null, 'data' => Arr::except($data, ['expense_ids'])];
    }

    /** @param  array<string, mixed>  $data */
    private function fileName(array $data): string
    {
        return 'A2-'.substr(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $data['beneficiary_name']), '-'), 0, 40).'-'.$data['date'].'.docx';
    }

    /** @param  array<string, mixed>  $data  @return array<int, string> */
    private function missing(array $data): array
    {
        return collect(['debit_bank_id' => 'bank account for the remittance', 'charges_bank_id' => 'bank account for charges', 'bank_account' => 'beneficiary account / IBAN', 'bank_name' => 'beneficiary bank', 'swift' => 'SWIFT code'])->filter(fn ($label, $key) => blank($data[$key] ?? null))->values()->all();
    }

    private function linkBills(RemittanceForm $form, $bills, Vendor $vendor): void
    {
        foreach ($bills as $bill) {
            $bill->forceFill(['a2_prepared_on' => today()->toDateString(), 'a2_debit_bank_id' => $form->debit_bank_id, 'a2_charges_bank_id' => $form->charges_bank_id, 'vendor_id' => $bill->vendor_id ?? $vendor->id])->saveQuietly();
        }
    }

    /** Bills no longer on any open or remitted form lose their "Form A2 prepared" mark. */
    private function releaseBills($ids): void
    {
        Expense::whereKey($ids)->whereDoesntHave('remittanceForms', fn ($q) => $q->whereIn('status', ['Prepared', 'Submitted', 'Remitted']))
            ->get()->each(fn (Expense $bill) => $bill->forceFill(['a2_prepared_on' => null])->saveQuietly());
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

    /** 123456.5 → "1,23,456.50" (Indian grouping). */
    public static function rupees(string $amount): string
    {
        $f = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
        $f->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $f->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return $f->format((float) $amount);
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
            'inr_equivalent' => filled($data['exchange_rate'] ?? null) ? self::rupees(Money::mul((string) $data['amount'], (string) $data['exchange_rate'])).' (at '.$data['exchange_rate'].')' : '_____________________________',
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
