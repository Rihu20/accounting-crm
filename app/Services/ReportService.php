<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceNote;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/** Accountant / Director reports. Amounts are always kept per currency. */
class ReportService
{
    public const BUCKETS = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, PHP_INT_MAX]];

    /** On one date a statement shows what was charged before what settled it. */
    private const STATEMENT_ORDER = ['Proforma' => 0, 'Debit Note' => 1, 'Credit Note' => 2, 'Payment received' => 3, 'TDS deducted' => 4, 'Short payment written off' => 5];

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

    /**
     * Unpaid vendor bills bucketed by days past their due date, per currency.
     *
     * @return array<string, array<string, string|int>>
     */
    public function payablesAgeing(): array
    {
        $out = [];
        foreach (Expense::where('expense_type', 'vendor')->where('status', 'Unpaid')->get() as $e) {
            $c = $e->currency;
            $out[$c] ??= ['Not due' => '0.00'] + array_fill_keys(array_keys(self::BUCKETS), '0.00') + ['total' => '0.00', 'count' => 0];
            $days = $this->overdueDays($e);
            $bucket = $days === null ? 'Not due' : collect(self::BUCKETS)->search(fn ($r) => $days >= $r[0] && $days <= $r[1]);
            $out[$c][$bucket] = bcadd($out[$c][$bucket], $this->money($e->amount), 2);
            $out[$c]['total'] = bcadd($out[$c]['total'], $this->money($e->amount), 2);
            $out[$c]['count']++;
        }
        ksort($out);

        return $out;
    }

    public function payablesAgeingRows(): array
    {
        $rows = [['Bill No', 'Payee', 'Group', 'Category', 'Currency', 'Bill Amount', 'TDS', 'Bill Date', 'Due Date', 'Days Overdue', 'Bucket', 'Payment request']];
        foreach (Expense::with(['vendor', 'patentOffice'])->where('expense_type', 'vendor')->where('status', 'Unpaid')->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('invoice_date')->get() as $e) {
            $days = $this->overdueDays($e);
            $rows[] = [
                $e->vendor_invoice_no, $this->payeeName($e), ExpenseGroups::LABELS[$e->expense_group] ?? $e->expense_group, $e->category,
                $e->currency, $this->money($e->amount), $this->money($e->tds_amount), $e->invoice_date->format('d.m.Y'),
                $e->due_date?->format('d.m.Y') ?? '', $days ?? 0, $days === null ? 'Not due' : collect(self::BUCKETS)->search(fn ($r) => $days >= $r[0] && $days <= $r[1]),
                $e->payment_request['status'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * TDS register for a period: deducted by clients on receipts, and deducted by us on vendor bills.
     *
     * @return array<string, array> sheet title => rows
     */
    public function tds(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $receipts = [['Received On', 'Txn Ref', 'Proforma No', 'Tax Invoice No', 'Client', 'GSTIN', 'Currency', 'Amount Received', 'TDS Deducted', 'Written Off', 'Invoice Value']];
        $byClient = [];
        foreach (Payment::where('reversed', false)->whereBetween('received_on', [$start->toDateString(), $end->toDateString()])->orderBy('received_on')->get() as $p) {
            if (bccomp((string) ($p->tds_amount ?? '0'), '0', 2) <= 0) {
                continue;
            }
            $invoice = Invoice::with('client')->find($p->invoice_id);
            $client = $invoice?->client;
            $receipts[] = [
                $p->received_on->format('d.m.Y'), $p->txn_ref, $invoice?->number, $invoice?->taxInvoice?->number, $client?->name,
                $client?->gstin ?? '', $p->currency, (string) $p->amount, (string) $p->tds_amount,
                (string) ($p->write_off_amount ?? '0.00'), (string) ($invoice?->grand_total ?? ''),
            ];
            $key = ($client?->name ?? 'Unknown').'|'.$p->currency;
            $byClient[$key] ??= ['client' => $client?->name ?? 'Unknown', 'currency' => $p->currency, 'tds' => '0.00', 'count' => 0];
            $byClient[$key]['tds'] = bcadd($byClient[$key]['tds'], (string) $p->tds_amount, 2);
            $byClient[$key]['count']++;
        }

        $bills = [['Bill Date', 'Payee', 'Bill No', 'Section', 'Currency', 'Taxable Value', 'TDS Rate %', 'TDS Deducted', 'Net Paid', 'Status', 'Paid On']];
        $bySection = [];
        foreach (Expense::with(['vendor', 'patentOffice'])->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])->orderBy('invoice_date')->get() as $e) {
            if (bccomp((string) ($e->tds_amount ?? '0'), '0', 2) <= 0) {
                continue;
            }
            $bills[] = [
                $e->invoice_date->format('d.m.Y'), $this->payeeName($e), $e->vendor_invoice_no, $e->tds_section ?: '', $e->currency,
                $e->taxable_amount === null ? '' : $this->money($e->taxable_amount), (string) ($e->tds_rate ?? ''), $this->money($e->tds_amount), $this->money($e->amount),
                $e->status, $e->paid_on?->format('d.m.Y') ?? '',
            ];
            $key = ($e->tds_section ?: 'Section not set').'|'.$e->currency;
            $bySection[$key] ??= ['section' => $e->tds_section ?: 'Section not set', 'currency' => $e->currency, 'tds' => '0.00', 'count' => 0];
            $bySection[$key]['tds'] = bcadd($bySection[$key]['tds'], (string) $e->tds_amount, 2);
            $bySection[$key]['count']++;
        }

        $summary = [['Period', $start->format('d.m.Y').' to '.$end->format('d.m.Y')], [], ['TDS deducted by clients (credit in Form 26AS)'], ['Client', 'Currency', 'Receipts', 'TDS']];
        foreach ($byClient as $row) {
            $summary[] = [$row['client'], $row['currency'], $row['count'], $row['tds']];
        }
        $summary[] = [];
        $summary[] = ['TDS deducted by us on vendor bills (payable to the department)'];
        $summary[] = ['Section', 'Currency', 'Bills', 'TDS'];
        foreach ($bySection as $row) {
            $summary[] = [$row['section'], $row['currency'], $row['count'], $row['tds']];
        }

        return ['Summary' => $summary, 'Receipts TDS' => $receipts, 'Vendor Bill TDS' => $bills];
    }

    /**
     * Statement of account for one client: one sheet per currency with a running balance.
     *
     * @return array<string, array> sheet title => rows
     */
    public function clientStatement(Client $client): array
    {
        $entries = [];
        $invoices = Invoice::with(['payments', 'notes'])->where('client_id', $client->id)->where('doc_type', 'proforma')->whereNotNull('number')->where('status', '!=', 'Draft')->get();
        foreach ($invoices as $i) {
            $entries[] = ['date' => ($i->sent_at ?? $i->invoice_date)->copy()->startOfDay(), 'type' => 'Proforma', 'ref' => $i->number, 'currency' => $i->currency, 'debit' => (string) $i->grand_total, 'credit' => '0.00'];
            foreach ($i->payments->where('reversed', false) as $p) {
                $entries[] = ['date' => $p->received_on, 'type' => 'Payment received', 'ref' => $p->txn_ref ?: $i->number, 'currency' => $p->currency, 'debit' => '0.00', 'credit' => (string) $p->amount];
                if (bccomp((string) ($p->tds_amount ?? '0'), '0', 2) > 0) {
                    $entries[] = ['date' => $p->received_on, 'type' => 'TDS deducted', 'ref' => $i->number, 'currency' => $p->currency, 'debit' => '0.00', 'credit' => (string) $p->tds_amount];
                }
                if (bccomp((string) ($p->write_off_amount ?? '0'), '0', 2) > 0) {
                    $entries[] = ['date' => $p->received_on, 'type' => 'Short payment written off', 'ref' => $i->number, 'currency' => $p->currency, 'debit' => '0.00', 'credit' => (string) $p->write_off_amount];
                }
            }
        }
        foreach (InvoiceNote::with('invoice')->whereIn('invoice_id', Invoice::where('client_id', $client->id)->pluck('id'))->get() as $n) {
            $entries[] = ['date' => $n->note_date, 'type' => $n->label(), 'ref' => $n->number, 'currency' => $n->currency, 'debit' => $n->type === 'debit' ? (string) $n->amount : '0.00', 'credit' => $n->type === 'debit' ? '0.00' : (string) $n->amount];
        }

        $sheets = [];
        foreach (collect($entries)->groupBy('currency')->sortKeys() as $currency => $group) {
            $rows = [['Statement of account – '.$client->name.' ('.$currency.')'], ['Client Ref', $client->ref_no ?: '', 'As on', today()->format('d.m.Y')], [], ['Date', 'Particulars', 'Reference', 'Debit', 'Credit', 'Balance']];
            $balance = '0.00';
            foreach ($group->sortBy(fn ($e) => [$e['date']->timestamp, self::STATEMENT_ORDER[$e['type']] ?? count(self::STATEMENT_ORDER)])->values() as $e) {
                $balance = bcsub(bcadd($balance, $e['debit'], 2), $e['credit'], 2);
                $rows[] = [$e['date']->format('d.m.Y'), $e['type'], $e['ref'], $e['debit'], $e['credit'], $balance];
            }
            $rows[] = ['', '', 'Closing balance due', '', '', $balance];
            $sheets[$currency] = $rows;
        }

        return $sheets ?: ['Statement' => [['Statement of account – '.$client->name], ['No invoices or payments recorded for this client.']]];
    }

    /** The Indian financial year (1 April – 31 March) that contains the given date. */
    public static function financialYear(?Carbon $on = null): array
    {
        $on ??= today();
        $start = Carbon::create($on->month >= 4 ? $on->year : $on->year - 1, 4, 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()];
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

    /** Days past the bill's due date (its invoice date when no due date is set), or null while it is not yet due. */
    private function overdueDays(Expense $expense): ?int
    {
        $days = (int) ($expense->due_date ?? $expense->invoice_date)->copy()->startOfDay()->diffInDays(today());

        return $days >= 0 ? $days : null;
    }

    /** Expense money columns are not all cast, so report cells are normalised to two decimals here. */
    private function money(mixed $value): string
    {
        return Money::round((string) ($value ?? '0'));
    }

    private function payeeName(Expense $expense): string
    {
        return $expense->payee_name ?: ($expense->vendor?->name ?? $expense->patentOffice?->name ?? '');
    }
}
