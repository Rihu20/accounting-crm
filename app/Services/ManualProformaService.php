<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualProformaService
{
    public function create(User $actor, array $input): Invoice
    {
        abort_unless($actor->is_active && $actor->allowed('accounting.invoices.create'), 403);
        foreach (['client_id', 'client_ref', 'paralegal_id', 'bank_account_id', 'gateway_id'] as $optional) {
            if (blank($input[$optional] ?? null)) {
                $input[$optional] = null;
            }
        }
        if (is_string($input['client_ref'] ?? null)) {
            $input['client_ref'] = trim($input['client_ref']);
        }
        $money = ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'];
        $data = Validator::make($input, [
            'client_id' => 'nullable|integer|exists:clients,id',
            'client_ref' => 'nullable|regex:/^\d{4}$/',
            'name' => 'required|string|max:255', 'contact_person' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:4000', 'email_1' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:100',
            'country' => 'required|string|max:255', 'client_type' => 'required|in:Indian,Foreign',
            'state' => 'nullable|string|max:255', 'state_code' => 'nullable|in:'.implode(',', array_values(Gst::STATE_CODES)),
            'gstin' => 'nullable|string|max:15', 'currency' => 'required|in:USD,INR,EUR',
            'invoice_layout' => 'required|in:combined,detailed', 'gst_enabled' => 'sometimes|boolean',
            'gst_rate' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'between:0,100'],
            'invoice_date' => 'required|date', 'due_date' => 'required|date|after_or_equal:invoice_date',
            'paralegal_id' => 'nullable|integer|exists:paralegals,id,deleted_at,NULL',
            'payment_method' => 'required|in:bank,gateway',
            'bank_account_id' => 'nullable|required_if:payment_method,bank|integer',
            'gateway_id' => 'nullable|required_if:payment_method,gateway|integer',
            'items' => 'required|array|min:1|max:100',
            'items.*.work' => 'required|string|max:2000',
            'items.*.applicant_name' => 'nullable|string|max:255',
            'items.*.app_no' => 'nullable|string|max:255', 'items.*.pct_no' => 'nullable|string|max:255',
            'items.*.country' => 'nullable|string|max:255', 'items.*.our_ref' => 'nullable|string|max:255',
            'items.*.your_ref' => 'nullable|string|max:255', 'items.*.language' => 'nullable|string|max:100',
            'items.*.combined_amount' => $money, 'items.*.official_amount' => $money,
            'items.*.professional_amount' => $money, 'items.*.translation_amount' => $money,
            'additional_charges' => 'nullable|array|max:10',
            'additional_charges.*.label' => 'required|string|max:80', 'additional_charges.*.amount' => $money,
        ])->validate();
        $indian = $data['client_type'] === 'Indian';
        $gst = $indian && ($data['gst_enabled'] ?? true);
        $rate = $gst ? (string) ($data['gst_rate'] ?? Setting::get('gst_rate', '18')) : '0';
        if ($gst && (blank($data['state'] ?? null) || ! in_array(Gst::stateCode(new Client($data)), array_values(Gst::STATE_CODES), true))) {
            throw ValidationException::withMessages(['state' => 'Select the Indian billing state before applying GST.']);
        }
        if ($indian && $data['currency'] !== 'INR') {
            throw ValidationException::withMessages(['currency' => 'Use INR for this Indian proforma.']);
        }
        foreach ($data['items'] as $index => $item) {
            if ($data['invoice_layout'] === 'combined' && bccomp((string) ($item['translation_amount'] ?? '0'), '0', 2) > 0) {
                throw ValidationException::withMessages(['invoice_layout' => 'Choose separate fees to show a Translation charge.']);
            }
        }
        $bank = null;
        $gateway = null;
        if ($data['payment_method'] === 'bank') {
            $bank = BankAccount::whereKey($data['bank_account_id'])->where('is_active', true)->where('currency', $data['currency'])->first();
            if (! $bank) {
                throw ValidationException::withMessages(['bank_account_id' => 'Choose an active bank account in the invoice currency.']);
            }
        } else {
            $gateway = PaymentGateway::whereKey($data['gateway_id'])->where('is_active', true)->first();
            if (! $gateway) {
                throw ValidationException::withMessages(['gateway_id' => 'Choose an active payment gateway.']);
            }
        }

        return DB::transaction(function () use ($actor, $data, $indian, $gst, $rate, $bank, $gateway): Invoice {
            $client = isset($data['client_id']) ? Client::findOrFail($data['client_id']) : null;
            if (! $client && filled($data['client_ref'] ?? null)) {
                $client = Client::where('ref_no', $data['client_ref'])->first();
            }
            if (! $client) {
                $client = Client::where('name', $data['name'])->first();
            }
            if ($client && ($client->name !== $data['name'] || $client->client_type !== $data['client_type'])) {
                throw ValidationException::withMessages(['name' => 'The selected name or reference belongs to another client. Review client details.']);
            }
            if ($client && filled($data['client_ref'] ?? null) && filled($client->ref_no) && $client->ref_no !== $data['client_ref']) {
                throw ValidationException::withMessages(['client_ref' => 'Client reference differs from the selected client.']);
            }
            $billing = Arr::only($data, ['name', 'contact_person', 'address', 'email_1', 'phone', 'country', 'client_type', 'state', 'state_code', 'gstin']);
            $billing['ref_no'] = $data['client_ref'] ?? $client?->ref_no;
            $billing['email_2'] = null;
            $client ??= Client::create($billing + ['default_currency' => $data['currency']]);
            $invoice = Invoice::create([
                'client_id' => $client->id, 'billing_details' => $billing, 'created_by' => $actor->id,
                'invoice_layout' => $indian ? 'india_gst' : $data['invoice_layout'],
                'combine_fees' => $data['invoice_layout'] === 'combined', 'gst_enabled' => $gst,
                'currency' => $data['currency'], 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'],
                'paralegal_id' => $data['paralegal_id'] ?? $actor->paralegal?->id,
                'payment_term_days' => (int) Carbon::parse($data['invoice_date'])->diffInDays($data['due_date']),
                'bank_account_id' => $bank?->id, 'gateway_id' => $gateway?->id,
                'additional_charges' => $data['additional_charges'] ?? [],
            ]);
            $sort = 0;
            foreach ($data['items'] as $index => $item) {
                $combined = ! $indian && $data['invoice_layout'] === 'combined';
                $fees = $combined ? ['Official + Professional Fees' => 'combined_amount'] : ['Official Fees' => 'official_amount', 'Professional Fees' => 'professional_amount', 'Translation' => 'translation_amount'];
                $entry = (string) Str::uuid();
                $added = false;
                foreach ($fees as $fee => $field) {
                    if (! isset($item[$field]) || $item[$field] === '') {
                        continue;
                    }
                    if ($fee === 'Translation' && bccomp((string) $item[$field], '0', 2) > 0 && blank($item['language'] ?? null)) {
                        throw ValidationException::withMessages(['items.'.$index.'.language' => 'Enter the translation language.']);
                    }
                    $invoice->items()->create([
                        'entry_key' => $entry, 'matter_id' => null, 'ref_no' => $billing['ref_no'],
                        'matter_text' => $item['work'], 'applicant_name' => $item['applicant_name'] ?? null,
                        'fee_type' => $fee, 'app_no' => $item['app_no'] ?? null, 'pct_no' => $item['pct_no'] ?? null,
                        'our_ref' => $item['our_ref'] ?? null, 'your_ref' => $item['your_ref'] ?? null,
                        'country' => $item['country'] ?? null, 'language' => $fee === 'Translation' ? ($item['language'] ?? null) : null,
                        'sac_code' => '998213', 'qty' => '1', 'rate' => $item[$field], 'amount' => $item[$field],
                        'taxable' => $fee === 'Professional Fees', 'tax_rate' => $rate, 'sort_order' => $sort++,
                    ]);
                    $added = true;
                }
                if (! $added) {
                    throw ValidationException::withMessages(['items.'.$index => 'Enter at least one fee amount for this matter.']);
                }
            }

            return app(InvoiceService::class)->recalculate($invoice);
        });
    }
}
