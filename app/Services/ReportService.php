<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceNote;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/** Accountant / Director reports. Amounts are always kept per currency. */
class ReportService
{
    public const BUCKETS = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, PHP_INT_MAX]];

    /** ['USD' => ['0-30' => '100.00', ..., 'total' => '...', 'count' => 3]] by days since the proforma was sent. */
    public function ageing(): array
    {
        $out = [];
        foreach (Invoice::with('payments')->where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received'])->get() as $i) {
            $c = $i->currency;
            $out[$c] ??= array_fill_keys(array_keys(self::BUCKETS), '0.00') + ['total' => '0.00', 'count' => 0];
            $days = (int) ($i->sent_at ?? $i->invoice_date)->copy()->startOfDay()->diffInDays(today());
            foreach (self::BUCKETS as $label => [$from, $to]) {
                if ($days >= $from && $days <= $to) {
                    $out[$c][$label] = bcadd($out[$c][$label], $i->outstandingAmount(), 2);
                }
            }
            $out[$c]['total'] = bcadd($out[$c]['total'], $i->outstandingAmount(), 2);
            $out[$c]['count']++;
        }
        ksort($out);

        return $out;
    }

    public function ageingRows(): array
    {
        $rows = [['Proforma No', 'Client Ref', 'Client', 'Paralegal', 'Currency', 'Invoice Value', 'Balance', 'Sent On', 'Days', 'Bucket', 'Client payment report']];
        foreach (Invoice::with(['client', 'paralegal', 'payments'])->where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received'])->orderBy('sent_at')->get() as $i) {
            $days = (int) ($i->sent_at ?? $i->invoice_date)->copy()->startOfDay()->diffInDays(today());
            $bucket = collect(self::BUCKETS)->search(fn ($r) => $days >= $r[0] && $days <= $r[1]);
            $rows[] = [$i->number, $i->client->ref_no, $i->client->name, $i->paralegal?->name, $i->currency, (string) $i->grand_total, $i->outstandingAmount(), ($i->sent_at ?? $i->invoice_date)->format('d.m.Y'), $days, $bucket, $i->payment_initiation['status'] ?? ''];
        }

        return $rows;
    }

    /** Last N months: invoiced (tax invoices) and received (payments), per currency. */
    public function monthly(int $months = 6): array
    {
        $rows = [];
        for ($m = $months - 1; $m >= 0; $m--) {
            $start = now()->startOfMonth()->subMonths($m);
            $end = $start->copy()->endOfMonth();
            $row = ['month' => $start->format('M Y'), 'invoiced' => [], 'received' => [], 'tax_count' => 0];
            foreach (Invoice::where('doc_type', 'tax')->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])->get(['currency', 'grand_total']) as $i) {
                $row['invoiced'][$i->currency] = bcadd($row['invoiced'][$i->currency] ?? '0', (string) $i->grand_total, 2);
                $row['tax_count']++;
            }
            foreach (Payment::where('reversed', false)->whereBetween('received_on', [$start->toDateString(), $end->toDateString()])->get(['currency', 'amount']) as $p) {
                $row['received'][$p->currency] = bcadd($row['received'][$p->currency] ?? '0', (string) $p->amount, 2);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** GSTR-1 working for a month: invoices (B2B / B2CL / B2CS / EXPWOP) and credit / debit notes. */
    public function gstr1(string $month): array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $invoices = [['Section', 'Invoice No', 'Invoice Date', 'Client', 'GSTIN', 'Place of Supply', 'Currency', 'Invoice Value (FC)', 'INR Rate', 'Invoice Value (INR)', 'Taxable Value (INR)', 'Non-GST / Official Fees (INR)', 'GST Rate %', 'IGST', 'CGST', 'SGST', 'LUT / Remarks']];
        foreach (Invoice::with(['client', 'items'])->where('doc_type', 'tax')->where('status', 'Issued')->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])->orderBy('number')->get() as $i) {
            $client = $i->client;
            $export = Gst::isExport($client);
            $rate = $i->currency === 'INR' ? '1' : ($i->inr_exchange_rate ? (string) $i->inr_exchange_rate : null);
            $inr = fn (string $v) => $rate ? Money::mul($v, $rate) : '';
            $taxable = '0.00';
            foreach ($i->items as $item) {
                if ($export || $item->fee_type === 'Professional Fees') {
                    $taxable = bcadd($taxable, (string) $item->amount, 2);
                }
            }
            if ($export) {
                $taxable = bcsub((string) $i->grand_total, '0', 2);
            }
            $other = bcsub(bcsub((string) $i->grand_total, $taxable, 2), (string) $i->tax_total, 2);
            $intra = Gst::intraState($client);
            $tax = (string) $i->tax_total;
            $half = Money::round(bcdiv($tax, '2', 4));
            $section = $export ? 'EXPWOP' : (filled($client->gstin) ? 'B2B' : (bccomp((string) $i->grand_total, '250000', 2) > 0 && ! $intra ? 'B2CL' : 'B2CS'));
            $invoices[] = [
                $section, $i->number, $i->invoice_date->format('d.m.Y'), $client->name, $client->gstin ?: '', Gst::placeOfSupply($client), $i->currency,
                (string) $i->grand_total, $rate ?? 'RATE NEEDED', $inr((string) $i->grand_total), $inr($taxable), $inr($other),
                $export ? '0' : (string) Setting::get('gst_rate', '18'),
                $intra ? '0.00' : $inr($tax), $intra ? $inr($half) : '0.00', $intra ? $inr(bcsub($tax, $half, 2)) : '0.00',
                $export ? Gst::lutLine() : '',
            ];
        }

        $notes = [['Note Type', 'Note No', 'Note Date', 'Against Invoice', 'Invoice Date', 'Client', 'GSTIN', 'Place of Supply', 'Currency', 'Note Value (FC)', 'INR Rate', 'Note Value (INR)', 'Taxable (INR)', 'IGST', 'CGST', 'SGST', 'Reason']];
        foreach (InvoiceNote::with('invoice.client')->whereBetween('note_date', [$start->toDateString(), $end->toDateString()])->orderBy('number')->get() as $n) {
            $i = $n->invoice;
            $rate = $i->currency === 'INR' ? '1' : ($i->inr_exchange_rate ? (string) $i->inr_exchange_rate : null);
            $inr = fn ($v) => $rate ? Money::mul((string) $v, $rate) : '';
            $notes[] = [$n->label(), $n->number, $n->note_date->format('d.m.Y'), $i->number, $i->invoice_date->format('d.m.Y'), $i->client->name, $i->client->gstin ?: '', Gst::placeOfSupply($i->client), $n->currency, (string) $n->amount, $rate ?? 'RATE NEEDED', $inr($n->amount), $inr($n->taxable_amount), $inr($n->igst), $inr($n->cgst), $inr($n->sgst), $n->reason];
        }

        return ['Invoices' => $invoices, 'Credit-Debit Notes' => $notes];
    }
}
