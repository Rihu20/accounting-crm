<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as WordSettings;

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

    public static function canPrepare(Expense $expense, User $user): bool
    {
        return $expense->expense_type === 'vendor' && $expense->currency !== 'INR'
            && ($user->allowed('accounting.expenses.pay') || $user->allowed('accounting.expenses.edit_all'));
    }

    /** @return array<string, mixed> */
    public function defaults(Expense $expense): array
    {
        $expense->loadMissing('vendor');
        $vendor = $expense->vendor;
        $bank = $this->foreignAccounts($expense->currency)->first(fn (BankAccount $b) => filled($expense->payment_bank_name) && str_starts_with((string) $expense->payment_bank_name, (string) $b->bank_name))
            ?? $this->foreignAccounts($expense->currency)->first();
        $info = collect([
            $expense->vendor_invoice_no ? 'Invoice '.$expense->vendor_invoice_no.($expense->invoice_date ? ' dated '.$expense->invoice_date->format('d.m.Y') : '') : null,
            $expense->our_ref ? 'Our ref '.$expense->our_ref : null,
        ])->filter()->implode('; ');

        return [
            'date' => today()->toDateString(),
            'amount' => (string) $expense->amount,
            'bank_account_id' => $bank?->id,
            'charges_account' => $bank ? (string) BankAccount::where('is_active', true)->where('currency', 'INR')->where('bank_name', $bank->bank_name)->value('account_no') : '',
            'beneficiary_name' => $vendor?->name ?: $expense->payee_name,
            'country' => $expense->party_country ?: $vendor?->country,
            'address' => $vendor?->address,
            'bank_account' => $vendor?->bank_account,
            'bank_name' => $vendor?->bank_name,
            'bank_address' => $vendor?->bank_address,
            'swift' => $vendor?->swift,
            'corr_bank_name' => $vendor?->corr_bank_name,
            'corr_bank_address' => $vendor?->corr_bank_address,
            'corr_bank_swift' => $vendor?->corr_bank_swift,
            'purpose' => self::setting('a2_purpose'),
            'charges' => self::setting('a2_charges'),
            'purpose_code' => self::setting('a2_purpose_code'),
            'purpose_desc' => self::setting('a2_purpose_desc'),
            'source_funds' => self::setting('a2_source_funds'),
            'info' => $info,
            'save_vendor' => false,
        ];
    }

    /** @return array<int, string> */
    public function bankOptions(string $currency): array
    {
        return $this->foreignAccounts($currency)->mapWithKeys(fn (BankAccount $b) => [$b->id => $b->bank_name.' '.$b->currency.' · '.($b->account_no ?: 'account no. not set')])->all();
    }

    /**
     * Builds the Word file, records that the form was prepared and, when asked, keeps the beneficiary details on the vendor.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, content: string, missing: array<int, string>}
     */
    public function prepare(Expense $expense, array $data, User $actor): array
    {
        abort_unless(self::canPrepare($expense, $actor), 403);
        $data = Validator::make($data, [
            'date' => 'required|date',
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0.01'],
            'bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'beneficiary_name' => 'required|string|max:255',
            'charges' => 'required|in:BEN,OUR,SHA',
            'save_vendor' => 'boolean',
        ] + collect(['charges_account', 'country', 'bank_account', 'bank_name', 'swift', 'corr_bank_name', 'corr_bank_swift', 'purpose_code', 'purpose_desc', 'source_funds'])->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:255'])->all()
          + collect(['address', 'bank_address', 'corr_bank_address', 'purpose', 'info'])->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:2000'])->all())->validate();

        $bank = isset($data['bank_account_id']) ? BankAccount::find($data['bank_account_id']) : null;
        $content = $this->document($expense, $data, $bank);

        DB::transaction(function () use ($expense, $data, $actor): void {
            if (($data['save_vendor'] ?? false) && $expense->vendor) {
                $expense->vendor->update(['address' => $data['address'] ?? null, 'bank_account' => $data['bank_account'] ?? null, 'bank_name' => $data['bank_name'] ?? null, 'bank_address' => $data['bank_address'] ?? null, 'swift' => $data['swift'] ?? null, 'corr_bank_name' => $data['corr_bank_name'] ?? null, 'corr_bank_address' => $data['corr_bank_address'] ?? null, 'corr_bank_swift' => $data['corr_bank_swift'] ?? null]);
            }
            $expense->forceFill(['a2_prepared_on' => today()->toDateString()])->saveQuietly();
            activity()->causedBy($actor)->performedOn($expense)->withProperties(['beneficiary' => $data['beneficiary_name'], 'amount' => $data['amount'].' '.$expense->currency])->log('Bank Form A2 prepared');
        });

        $missing = collect(['bank_account' => 'beneficiary account / IBAN', 'bank_name' => 'beneficiary bank', 'swift' => 'SWIFT code'])->filter(fn ($label, $key) => blank($data[$key] ?? null))->values()->all();
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $data['beneficiary_name']), '-');

        return ['name' => 'A2-'.substr($slug, 0, 40).'-'.$data['date'].'.docx', 'content' => $content, 'missing' => $missing];
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

    private function foreignAccounts(string $currency)
    {
        return BankAccount::where('is_active', true)->where('currency', $currency)->orderByDesc('is_default')->orderBy('bank_name')->get();
    }

    /** @param  array<string, mixed>  $data */
    private function document(Expense $expense, array $data, ?BankAccount $bank): string
    {
        $currency = $expense->currency;
        $amount = number_format((float) $data['amount'], $currency === 'JPY' ? 0 : 2);
        $company = (string) Setting::get('company_name', '');
        $bankName = (string) ($bank?->bank_name ?? '');
        $start = today()->month >= 4 ? today()->year : today()->year - 1;

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(9);
        $s = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 850, 'marginRight' => 850]);
        $blank = '______________________';
        $value = fn (?string $v): array => [filled($v) ? $v : $blank, filled($v)];
        $line = function (Section $s, array $parts, array $para = []): void {
            $run = $s->addTextRun($para + ['spaceAfter' => 80]);
            foreach ($parts as $p) {
                [$text, $bold] = is_array($p) ? $p : [$p, false];
                $run->addText((string) $text, ['bold' => $bold]);
            }
        };
        $just = ['alignment' => 'both'];
        $table = function (Section $s, array $rows, array $widths): void {
            $t = $s->addTable(['borderSize' => 4, 'borderColor' => '000000', 'cellMargin' => 60, 'layout' => 'fixed']);
            foreach ($rows as $row) {
                $t->addRow();
                foreach ($row as $i => $cell) {
                    $c = $t->addCell($widths[$i]);
                    // A cell is plain text, or a list of lines; a line is a list of parts; a part is text or [text, bold].
                    foreach (is_string($cell) ? [[$cell]] : $cell as $lineParts) {
                        $run = $c->addTextRun(['spaceAfter' => 0]);
                        foreach ($lineParts as $p) {
                            [$text, $bold] = is_array($p) ? $p : [$p, false];
                            $run->addText((string) $text, ['bold' => $bold]);
                        }
                    }
                }
            }
            $s->addTextBreak(1, null, ['spaceAfter' => 60]);
        };
        $sign = fn () => $line($s, ['Name: ', [$company, true], '          Signature: '.$blank], ['spaceBefore' => 120, 'spaceAfter' => 120]);
        $box = fn (string $k): string => ($data['charges'] === $k ? '☒ ' : '☐ ').$k;

        $s->addText('FORM A2', ['bold' => true, 'size' => 14], ['alignment' => 'center', 'spaceAfter' => 20]);
        $s->addText('(To be completed by the applicant)', [], ['alignment' => 'center', 'spaceAfter' => 120]);
        $table($s, [[
            [['(For payments other than imports of goods and remittances covering intermediary trade)'], [''], [['Application for Remittance Abroad', true]]],
            [['Form No. '.$blank], ['AD Code No. '.$blank], ['UCIC No. '.$blank], ['(To be filled in by the Authorised Dealer)'], [''], ['Currency ', [$currency, true], '   Amount ', [$amount, true]], ['Equivalent to Rupees '.$blank], ['Amount in words ', [self::words((string) $data['amount'], $currency), true]]],
        ]], [4400, 5800]);
        $line($s, ['I/We  ', $value($company)]);
        $line($s, ['PAN No.  ', $value(self::companyPan())]);
        $line($s, ['Address  ', $value((string) Setting::get('company_address', ''))]);
        $line($s, ['Authorise  ', $value($bankName), ' (Name of AD branch)']);
        $line($s, ['To debit my RFC/EEFC A/c. No.  ', $value($bank?->account_no), '  for (FCY & amount)  ', [$currency.' '.$amount, true]]);
        $line($s, ['Savings Bank/Current A/c. No '.$blank.' for (FCY & amount) '.$blank]);
        $line($s, ['A/c. No.  ', $value($data['charges_account'] ?? null), '  for charges and effect the foreign exchange remittance directly.'], ['spaceAfter' => 120]);
        $table($s, collect([
            'Beneficiary Name' => $value($data['beneficiary_name']),
            'Beneficiary Address' => $value($data['address'] ?? null),
            'Beneficiary Account No. / IBAN No.' => $value($data['bank_account'] ?? null),
            'Beneficiary Bank Name' => $value($data['bank_name'] ?? null),
            'Beneficiary Bank Address' => $value($data['bank_address'] ?? null),
            'Swift code / ABA / Sort code / Routing No / BLZ No' => $value($data['swift'] ?? null),
            'Purpose of Remittance' => $value($data['purpose'] ?? null),
            'Correspondent Bank Charges' => [$box('BEN').'      '.$box('OUR').'      '.$box('SHA'), false],
            'Correspondent Bank Name' => [(string) ($data['corr_bank_name'] ?? ''), true],
            'Correspondent Bank Address' => [(string) ($data['corr_bank_address'] ?? ''), true],
            'Correspondent Bank Swift Code' => [(string) ($data['corr_bank_swift'] ?? ''), true],
            'Additional Information' => [(string) ($data['info'] ?? ''), true],
        ])->map(fn ($v, $label) => [$label, [[$v]]])->values()->all(), [3600, 6600]);
        $table($s, [['Sr.No.', 'Whether under LRS (Y/N)', 'Purpose Code', 'Purpose Code Description'], ['1', 'No', [[[(string) ($data['purpose_code'] ?? ''), true]]], [[[(string) ($data['purpose_desc'] ?? ''), true]]]]], [1000, 2400, 2000, 4800]);
        $s->addText('Charges claimed by foreign banks – In certain instances, the foreign bank(s) may claim their charges from '.($bankName ?: 'the bank').' subsequently, as per applicable rules and regulations of the respective country. By accepting these terms and conditions, the user hereby irrevocably authorises '.($bankName ?: 'the bank').' to debit the amount of such charges to his/her account as and when such claim is received.', [], $just);
        $s->addText('Note – 1. Additional purpose code is required for remittance to UAE and for THB currency transactions. 2. IBAN number is mandatory for UAE, European countries and the United Kingdom.', [], $just);
        $sign();
        $s->addText('Payment for import of services (Purpose Group Nos. 02, 03, 05, 06, 07, 08, 09, 10, 11, 15, 16 or 17), please indicate:');
        $line($s, ['Name of the country providing ultimate services:  ', $value($data['country'] ?? null)], ['spaceAfter' => 120]);
        $s->addText('Declaration (Under FEMA 1999)', ['bold' => true], ['alignment' => 'center']);
        $s->addText('I, '.$company.' hereby declare that the total amount of foreign exchange purchased from or remitted through all sources in India during the financial year, including this application, is as per the extant FEMA Regulations and certify that the source of funds for making the said remittance belongs to me and the foreign exchange will not be used for prohibited purposes / foreign exchange purchased from you is for the purpose indicated above.', [], $just);
        $s->addText('I/We hereby declare that the transaction, details of which are specifically mentioned above, does not involve and is not designed for the purpose of any contravention or evasion of the provisions of the aforesaid Act or of any rule, regulation, notification, direction or order made thereunder. I/We also agree and undertake to give such information / documents as will reasonably satisfy you about this transaction in terms of the above declaration. I/We understand that if I/We refuse to comply with any such requirement or make only unsatisfactory compliance therewith, the Bank shall refuse in writing to undertake the transaction and shall, if it has reason to believe that any contravention / evasion is contemplated by me/us, report the matter to the Reserve Bank of India.', [], $just);
        $s->addText('I / We declare and understand that the foreign exchange to be acquired / payment to be made by me / us pursuant to this application shall be used / made by me / us only for the purpose for which it is acquired / to be made and that the conditions subject to which the exchange / permission is granted will be complied with.', [], $just);
        $line($s, ['Source of Funds for this remittance  ', $value($data['source_funds'] ?? null)]);
        $s->addText('I / We hereby declare that the statements made by me / us on this form are true and that I / we have not applied for an authorisation through any other bank (applicable only for LRS capital account transactions).', [], $just);
        $sign();
        $s->addText('Details of the remittances made / transactions effected under the Liberalised Remittance Scheme in the current Financial Year (April–March) '.$start.'-'.($start + 1));
        $table($s, array_merge([['Sr.No.', 'Date', 'FCY and Amount', 'Name and address of AD branch / FFMC through which the transaction has been effected']], array_fill(0, 3, ['', '', '', ''])), [1000, 1800, 2400, 5000]);
        $s->addText('I confirm that the received / realised / unspent / unused foreign exchange, unless reinvested, shall be repatriated and surrendered to an authorised person within a period of 180 days from the date of such receipt / realisation / purchase / acquisition or date of return to India, as the case may be, in accordance with Regulation 7 of the Foreign Exchange Management (Realisation, repatriation and surrender of foreign exchange) Regulations, 2015.', [], $just);
        $sign();
        $line($s, ['Date: ', [Carbon::parse($data['date'])->format('d.m.Y'), true]], ['spaceAfter' => 200]);
        $s->addText('Certificate by the Authorised Dealer', ['bold' => true], ['alignment' => 'center']);
        $s->addText('This is to certify that the remittance is not being made by / to ineligible entities and that the remittance is in conformity with the instructions issued by the Reserve Bank from time to time under the Scheme.', [], $just);
        foreach (['Name and Employee ID of the authorised official:', 'Signature:', 'Date:', 'Stamp and seal:'] as $label) {
            $s->addText($label);
        }

        $escaping = WordSettings::isOutputEscapingEnabled();
        WordSettings::setOutputEscapingEnabled(true);
        $path = tempnam(sys_get_temp_dir(), 'a2');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);

            return (string) file_get_contents($path);
        } finally {
            WordSettings::setOutputEscapingEnabled($escaping);
            @unlink($path);
        }
    }
}
