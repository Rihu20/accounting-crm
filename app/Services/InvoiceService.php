<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceConversion;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvoiceService
{
    public function draft(User $actor, int $clientId, string $currency, array $rows, ?int $uploadId = null, ?string $layout = null, array $additionalCharges = []): Invoice
    {
        return DB::transaction(function () use ($actor, $clientId, $currency, $rows, $uploadId, $layout, $additionalCharges) {
            abort_unless(in_array($currency, ['USD', 'INR', 'EUR']), 422, 'Invalid currency');
            abort_unless($actor->is_active && $actor->allowed('accounting.invoices.create'), 403);
            abort_unless(count($rows) > 0, 422, 'Add at least one fee');
            $layout = InvoiceFormat::resolve($layout, Client::findOrFail($clientId));
            Validator::make(['rows' => $rows], [
                'rows' => 'required|array|max:500',
                'rows.*.rate' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,8})?$/', 'min:0'],
                'rows.*.qty' => ['sometimes', 'required', 'numeric', 'regex:/^\d+(\.\d{1,8})?$/', 'min:0.01'],
            ])->validate();
            InvoiceFormat::validateRows($layout, $rows);
            foreach ($rows as $row) {
                abort_unless(filled($row['matter_ref'] ?? $row['ref_no'] ?? null) && in_array($row['fee_type'] ?? '', InvoiceFormat::FEES) && is_numeric($row['rate'] ?? null) && bccomp((string) $row['rate'], '0', 2) >= 0 && bccomp((string) ($row['qty'] ?? '1'), '0', 2) > 0, 422, 'Invalid fee row');
            }
            $invoice = Invoice::create(['additional_charges' => $additionalCharges, 'invoice_layout' => $layout, 'client_id' => $clientId, 'currency' => $currency, 'invoice_date' => today(), 'due_date' => today()->addDays(15), 'created_by' => $actor->id, 'word_upload_id' => $uploadId, 'bank_account_id' => BankAccount::where('currency', $currency)->where('is_active', true)->orderByDesc('is_default')->value('id')]);
            foreach ($rows as $pos => $row) {
                $m = MatterReference::resolve($row['matter_ref'] ?? $row['ref_no'], $actor, $clientId);
                abort_unless($m->client_id === $clientId, 422, 'All matters must belong to one client');
                $appNo = array_key_exists('app_no', $row) ? $row['app_no'] : $m->app_no;
                $pctNo = array_key_exists('pct_no', $row) ? $row['pct_no'] : $m->pct_no;
                if (array_key_exists('app_no', $row) && ! array_key_exists('pct_no', $row) && $appNo !== $m->app_no) {
                    $pctNo = null;
                }
                if (array_key_exists('pct_no', $row) && ! array_key_exists('app_no', $row) && $pctNo !== $m->pct_no) {
                    $appNo = null;
                }
                $invoice->items()->create(['matter_id' => $m->id, 'ref_no' => $m->ref_no, 'fee_type' => $row['fee_type'], 'matter_text' => $row['matter_text'] ?? $m->matter_title, 'language' => $row['language'] ?? null, 'country' => $m->country, 'app_no' => $appNo, 'pct_no' => $pctNo, 'our_ref' => $m->our_ref ?: $m->ref_no, 'your_ref' => array_key_exists('your_ref', $row) ? $row['your_ref'] : $m->your_ref, 'qty' => $row['qty'] ?? '1', 'rate' => $row['rate'], 'amount' => Money::mul((string) ($row['qty'] ?? '1'), $row['rate']), 'taxable' => $row['fee_type'] === 'Professional Fees', 'tax_rate' => Setting::get('gst_rate', '18'), 'sort_order' => $pos]);
            }
            $invoice->update(['paralegal_id' => $invoice->items->first()?->matter?->paralegal_id]);

            return $this->recalculate($invoice);
        });
    }

    public function recalculate(Invoice $invoice): Invoice
    {
        InvoiceFormat::resolve($invoice->invoice_layout, $invoice->client);
        InvoiceFormat::validateRows($invoice->invoice_layout, $invoice->items()->get()->toArray());
        $sub = '0';
        $dis = '0';
        $tax = '0';
        foreach ($invoice->items()->get() as $item) {
            $amount = Money::mul($item->qty, $item->rate);
            $item->update(['amount' => $amount, 'taxable' => $item->fee_type === 'Professional Fees']);
            if ($item->fee_type === 'Disbursement') {
                $dis = bcadd($dis, $amount, 2);
            } else {
                $sub = bcadd($sub, $amount, 2);
            }if ($invoice->client->client_type === 'Indian' && $item->fee_type === 'Professional Fees') {
                $tax = bcadd($tax, Money::mul($amount, bcdiv($item->tax_rate, '100', 8)), 2);
            }
        }
        $extra = '0.00';
        foreach ($invoice->additional_charges ?? [] as $charge) {
            $extra = bcadd($extra, (string) $charge['amount'], 2);
        }
        $total = bcadd(bcadd(bcadd($sub, $dis, 2), $tax, 2), $extra, 2);
        $invoice->update(['additional_charges_total' => $extra, 'subtotal' => $sub, 'disbursement_total' => $dis, 'tax_total' => $tax, 'grand_total' => $total, 'amount_in_words' => Money::words($total, $invoice->currency)]);

        return $invoice->fresh();
    }

    public function number(Invoice $invoice): string
    {
        $tax = $invoice->doc_type === 'tax';

        return $this->nextNumber($tax ? 'tax' : 'proforma', $invoice->invoice_date, $tax ? 'AIP' : 'PI');
    }

    /** Sequential, gap-free serial per series and year (financial year Apr–Mar by default, as GST requires). */
    public function nextNumber(string $series, $date, string $defaultPrefix): string
    {
        $date = \Illuminate\Support\Carbon::parse($date);
        $year = (int) $date->format('Y');
        if (Setting::get('year_logic', 'financial') === 'financial' && $date->month < 4) {
            $year--;
        }
        DB::table('number_sequences')->insertOrIgnore(['series' => $series, 'year_key' => (string) $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $sequence = DB::table('number_sequences')->where('series', $series)->where('year_key', (string) $year)->lockForUpdate()->first();
        $next = $sequence->last_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return Setting::get($series.'_prefix', $defaultPrefix).substr((string) $year, -2).str_pad((string) $next, (int) Setting::get('sequence_padding', 4), '0', STR_PAD_LEFT);
    }

    public function send(Invoice $invoice): Invoice
    {
        abort_unless(auth()->user()?->allowed('accounting.invoices.finalise'), 403);

        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->doc_type === 'proforma' && $invoice->status === 'Draft' && $invoice->items()->exists(), 422, 'Only a populated draft can be sent');
            $client = $invoice->client;
            abort_if($client->client_type === 'Indian' && blank($client->state), 422, 'Add the state of '.$client->name.' in Clients first – it decides CGST+SGST or IGST (place of supply).');
            $invoice->update(['number' => $this->number($invoice), 'status' => 'Sent', 'sent_at' => now()]);
            app(PdfService::class)->save($invoice);
            app(NotifyService::class)->paralegals($invoice, 'Proforma sent', $invoice->number.' is awaiting payment.');

            return $invoice->fresh();
        });
    }

    public function convert(Invoice $invoice, string $target, string $rate, string $date, string $source, User $actor): Invoice
    {
        abort_unless($actor->allowed('accounting.invoices.finalise'), 403);

        Validator::make(['rate' => $rate, 'date' => $date], [
            'rate' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,8})?$/', 'gt:0'],
            'date' => 'required|date',
        ])->validate();

        return DB::transaction(function () use ($invoice, $target, $rate, $date, $source, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->status === 'Draft' && in_array($target, ['USD', 'INR', 'EUR']) && $target !== $invoice->currency && bccomp($rate, '0', 8) > 0, 422, 'Conversion requires a draft, another currency and a positive rate');
            $before = $invoice->grand_total;
            $old = $invoice->currency;
            $items = $invoice->items->toArray();
            $originalCharges = $invoice->additional_charges ?? [];
            $convertedCharges = array_map(fn ($charge) => ['label' => $charge['label'], 'amount' => Money::mul((string) $charge['amount'], $rate)], $originalCharges);
            foreach ($invoice->items as $item) {
                $item->update(['rate' => bcmul($item->rate, $rate, 8)]);
            }$invoice->update(['additional_charges' => $convertedCharges, 'currency' => $target, 'bank_account_id' => BankAccount::where('currency', $target)->where('is_active', true)->orderByDesc('is_default')->value('id')]);
            $invoice = $this->recalculate($invoice);
            InvoiceConversion::create(['invoice_id' => $invoice->id, 'source_currency' => $old, 'source_amount' => $before, 'target_currency' => $target, 'rate' => $rate, 'rate_date' => $date, 'rate_source' => $source, 'converted_amount' => $invoice->grand_total, 'original_items' => $items, 'original_charges' => $originalCharges, 'user_id' => $actor->id]);

            return $invoice;
        });
    }

    public function orderTax(Invoice $proforma, string $date, User $actor): Invoice
    {
        abort_unless($actor->is_active && $actor->allowed('accounting.tax.issue'), 403);

        Validator::make(['date' => $date], ['date' => 'required|date'])->validate();

        return DB::transaction(function () use ($proforma, $date, $actor) {
            $proforma = Invoice::lockForUpdate()->findOrFail($proforma->id);
            abort_unless($proforma->doc_type === 'proforma' && $proforma->status === 'Received', 422, 'Full payment must be received before ordering the tax invoice');
            $authorization = $proforma->tax_authorization;
            // Accountant may issue directly. Everyone else needs Director / Accountant approval first.
            if (! PortalWorkflow::canAuthorizeTax($actor) || in_array($authorization['status'] ?? null, ['Pending', 'Rejected'])) {
                abort_unless(($authorization['status'] ?? null) === 'Approved', 403, 'Director or Accountant approval is required before the tax invoice.');
            }
            $tax = $proforma->replicate(['number', 'sent_at', 'pdf_path', 'proforma_id', 'payment_initiation', 'tax_authorization']);
            $tax->fill(['proforma_id' => $proforma->id, 'doc_type' => 'tax', 'invoice_date' => $date, 'status' => 'Issued', 'created_by' => $actor->id]);
            $tax->number = $this->number($tax);
            $tax->save();
            foreach ($proforma->items as $item) {
                $copy = $item->replicate();
                $copy->invoice_id = $tax->id;
                $copy->save();
            }$proforma->update(['status' => 'Converted']);
            app(PdfService::class)->save($tax);
            app(FolderService::class)->ensureForTaxInvoice($tax);
            app(NotifyService::class)->paralegals($tax, 'Tax invoice generated', $tax->number.' dated '.$tax->invoice_date->format('d.m.Y').'. Upload completed work in Documents.');

            return $tax;
        });
    }
}
