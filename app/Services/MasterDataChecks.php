<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Carbon;

class MasterDataChecks
{
    /** Review saved details without changing them. Samples are capped; counts cover all active clients. */
    public function clients(): array
    {
        $checks = [];
        foreach ([
            'reference' => ['Missing client reference', 'Optional for a manual proforma. Add a reference later when one is assigned.'],
            'address' => ['Missing billing address', 'Add the billing address before issuing an invoice.'],
            'currency' => ['Review Indian client currency', 'This Indian client defaults to a foreign currency. Review the intended billing currency.'],
            'state' => ['Missing Indian place of supply', 'Add a state or state code so the GST split can be determined.'],
            'email' => ['Missing billing email', 'Add a billing email if invoices will be sent by email.'],
        ] as $key => [$title, $help]) {
            $checks[$key] = ['title' => $title, 'help' => $help, 'count' => 0, 'records' => []];
        }

        Client::query()->select(['id', 'name', 'ref_no', 'address', 'client_type', 'default_currency', 'state', 'state_code', 'gstin', 'email_1', 'email_2'])->chunkById(200, function ($clients) use (&$checks): void {
            foreach ($clients as $client) {
                $flags = [
                    'reference' => blank($client->ref_no),
                    'address' => blank($client->address),
                    'currency' => $client->client_type === 'Indian' && $client->default_currency !== 'INR',
                    'state' => $client->client_type === 'Indian' && ! in_array(Gst::stateCode($client), array_values(Gst::STATE_CODES), true),
                    'email' => blank($client->email_1) && blank($client->email_2),
                ];
                foreach ($flags as $key => $flag) {
                    if (! $flag) {
                        continue;
                    }
                    $checks[$key]['count']++;
                    if (count($checks[$key]['records']) < 20) {
                        $checks[$key]['records'][] = ['id' => $client->id, 'name' => $client->name, 'ref_no' => $client->ref_no];
                    }
                }
            }
        });

        return $checks;
    }

    /**
     * Indian purchases whose PAN, GSTIN or TDS details look wrong. Nothing is changed.
     *
     * @return array{count: int, records: array<int, array{id: int, party: string, invoice_no: ?string, problems: array<int, string>}>}
     */
    public function indianBills(): array
    {
        $out = ['count' => 0, 'records' => []];
        Expense::query()->where('expense_group', 'indian')->select(['id', 'payee_name', 'vendor_invoice_no', 'pan_number', 'gstin', 'gst_rate', 'taxable_amount', 'tds_rate'])->chunkById(200, function ($expenses) use (&$out): void {
            foreach ($expenses as $expense) {
                $problems = self::billProblems($expense);
                if (! $problems) {
                    continue;
                }
                $out['count']++;
                if (count($out['records']) < 30) {
                    $out['records'][] = ['id' => $expense->id, 'party' => (string) $expense->payee_name, 'invoice_no' => $expense->vendor_invoice_no, 'problems' => $problems];
                }
            }
        });

        return $out;
    }

    /**
     * Invoice, LUT and bank details that would make a GST return, an export invoice or a payment wrong.
     *
     * @return array<int, array{area: string, record: string, problem: string}>
     */
    public function accountsChecks(): array
    {
        $out = [];
        Invoice::where('doc_type', 'tax')->where('currency', '!=', 'INR')->where('status', '!=', 'Cancelled')->whereNull('inr_exchange_rate')->orderBy('invoice_date')->limit(50)
            ->get(['id', 'number', 'currency'])->each(function (Invoice $i) use (&$out): void {
                $out[] = ['area' => 'Invoice', 'record' => (string) $i->number, 'problem' => 'INR exchange rate missing – needed for the GSTR-1 export value and the e-invoice (Register details)'];
            });
        Invoice::where('status', 'Draft')->where('created_at', '<=', now()->subDays(7))->orderBy('created_at')->limit(50)
            ->get(['id', 'created_at'])->each(function (Invoice $i) use (&$out): void {
                $out[] = ['area' => 'Invoice', 'record' => 'Draft #'.$i->id, 'problem' => 'Draft older than 7 days ('.$i->created_at->format('d.m.Y').') – finalise or delete it'];
            });
        $exports = Client::where('client_type', '!=', 'Indian')->exists();
        $arn = trim((string) Setting::get('lut_arn', ''));
        $validUpto = Setting::get('lut_valid_upto');
        if ($exports && $arn === '') {
            $out[] = ['area' => 'Setting', 'record' => 'LUT ARN', 'problem' => 'LUT ARN is not set – export invoices print the LUT line without it'];
        }
        if ($exports && filled($validUpto)) {
            $until = Carbon::parse($validUpto);
            if ($until->lt(today()->addDays(60))) {
                $out[] = ['area' => 'Setting', 'record' => 'LUT valid up to', 'problem' => $until->isPast() ? 'LUT expired on '.$until->format('d.m.Y').' – file a new LUT' : 'LUT expires on '.$until->format('d.m.Y').' – renew it in time'];
            }
        }
        BankAccount::where('is_active', true)->where(fn ($q) => $q->whereNull('account_no')->orWhere('account_no', ''))->get(['bank_name', 'currency'])
            ->each(function (BankAccount $b) use (&$out): void {
                $out[] = ['area' => 'Bank', 'record' => $b->bank_name.' '.$b->currency, 'problem' => 'Account number missing – printed blank on invoices'];
            });

        return $out;
    }

    /** @return array<int, string> */
    public static function billProblems(Expense $expense): array
    {
        $pan = strtoupper(trim((string) $expense->pan_number));
        $gstin = strtoupper(trim((string) $expense->gstin));
        $problems = [];
        if ((float) $expense->tds_rate > 0 && $pan === '') {
            $problems[] = 'TDS deducted but no PAN';
        }
        if ($pan !== '' && ! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            $problems[] = 'PAN '.$pan.' does not look valid';
        }
        if ($gstin !== '' && ! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            $problems[] = 'GSTIN '.$gstin.' does not look valid';
        }
        if ($pan !== '' && strlen($gstin) === 15 && substr($gstin, 2, 10) !== $pan) {
            $problems[] = 'PAN does not match the PAN inside the GSTIN';
        }
        if ($gstin !== '' && (float) $expense->gst_rate <= 0 && (float) $expense->taxable_amount > 0) {
            $problems[] = 'Vendor has a GSTIN but no GST rate is entered';
        }
        if ($gstin === '' && (float) $expense->gst_rate > 0) {
            $problems[] = 'GST charged but the vendor GSTIN is missing';
        }

        return $problems;
    }
}
