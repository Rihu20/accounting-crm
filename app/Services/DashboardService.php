<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

/** Numbers for the role dashboards. Everything is grouped per currency – never added across currencies. */
class DashboardService
{
    public function __construct(private User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function role(): string
    {
        return PlatformAccess::role($this->user) ?? '';
    }

    /** ['USD' => ['amount' => '1200.00', 'count' => 3], ...] */
    public function receivables(): array
    {
        $out = [];
        foreach (Invoice::with('payments')->where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received'])->get() as $i) {
            $c = $i->currency;
            $out[$c] ??= ['amount' => '0.00', 'count' => 0];
            $out[$c]['amount'] = bcadd($out[$c]['amount'], $i->outstandingAmount(), 2);
            $out[$c]['count']++;
        }

        return $this->sorted($out);
    }

    public function receivedThisMonth(): array
    {
        $out = [];
        foreach (Payment::where('reversed', false)->whereBetween('received_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->get() as $p) {
            $out[$p->currency] ??= ['amount' => '0.00', 'count' => 0];
            $out[$p->currency]['amount'] = bcadd($out[$p->currency]['amount'], (string) $p->amount, 2);
            $out[$p->currency]['count']++;
        }

        return $this->sorted($out);
    }

    public function payables(): array
    {
        $out = [];
        foreach (Expense::where('expense_type', 'vendor')->where('status', 'Unpaid')->get(['currency', 'amount']) as $e) {
            $out[$e->currency] ??= ['amount' => '0.00', 'count' => 0];
            $out[$e->currency]['amount'] = bcadd($out[$e->currency]['amount'], (string) $e->amount, 2);
            $out[$e->currency]['count']++;
        }

        return $this->sorted($out);
    }

    public function paidThisMonth(): array
    {
        $out = [];
        foreach (Expense::where('expense_type', 'vendor')->where('status', 'Paid')->whereBetween('paid_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->get(['currency', 'amount']) as $e) {
            $out[$e->currency] ??= ['amount' => '0.00', 'count' => 0];
            $out[$e->currency]['amount'] = bcadd($out[$e->currency]['amount'], (string) $e->amount, 2);
            $out[$e->currency]['count']++;
        }

        return $this->sorted($out);
    }

    public function overdueCount(): int
    {
        return Invoice::where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received'])->where('sent_at', '<=', now()->subDays((int) \App\Models\Setting::get('followup_days', 15)))->count();
    }

    public function approvals(): array
    {
        return [
            'tax' => Invoice::where('doc_type', 'proforma')->where(fn ($q) => $q->where('tax_authorization->status', 'Pending')->orWhere(fn ($p) => $p->where('status', 'Received')->whereNull('tax_authorization')))->whereIn('status', ['Sent', 'Partially received', 'Received'])->count(),
            'client_payments' => Invoice::where('payment_initiation->status', 'Pending')->count(),
            'expenses' => Expense::where('status', 'Unpaid')->where('payment_request->status', 'Pending')->count(),
        ];
    }

    public function pendingReceivables(int $limit = 6): Collection
    {
        return Invoice::with(['client', 'payments'])->where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received'])->orderBy('sent_at')->limit($limit)->get();
    }

    public function unpaidPayables(int $limit = 6, bool $paralegalOnly = false): Collection
    {
        return Expense::with(['vendor', 'patentOffice'])->where('expense_type', 'vendor')->where('status', 'Unpaid')
            ->when($paralegalOnly, fn ($q) => $q->whereIn('expense_group', PortalWorkflow::PARALEGAL_GROUPS))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('invoice_date')->limit($limit)->get();
    }

    public function recentPayments(int $limit = 5): Collection
    {
        return Payment::with('markedBy')->where('reversed', false)->latest('received_on')->latest('id')->limit($limit)->get()->each(fn ($p) => $p->setRelation('invoiceRef', Invoice::with('client')->find($p->invoice_id)));
    }

    public function assistantQueue(): array
    {
        return [
            'drafts' => Invoice::where('status', 'Draft')->count(),
            'ready' => Invoice::where('doc_type', 'proforma')->where('status', 'Received')->where('tax_authorization->status', 'Approved')->count(),
            'rejected' => Invoice::where('doc_type', 'proforma')->where('tax_authorization->status', 'Rejected')->whereIn('status', ['Sent', 'Partially received', 'Received'])->count(),
            'proformas_month' => Invoice::where('doc_type', 'proforma')->whereNotNull('number')->whereBetween('sent_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'tax_month' => Invoice::where('doc_type', 'tax')->whereBetween('invoice_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            'expenses_month' => Expense::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
    }

    public function readyForTax(int $limit = 6): Collection
    {
        return Invoice::with('client')->where('doc_type', 'proforma')->where('status', 'Received')->where('tax_authorization->status', 'Approved')->latest('updated_at')->limit($limit)->get();
    }

    public function drafts(int $limit = 6): Collection
    {
        return Invoice::with('client')->where('status', 'Draft')->latest()->limit($limit)->get();
    }

    public function paralegalSummary(): array
    {
        return [
            'awaiting' => Invoice::where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received'])->count(),
            'tax_month' => Invoice::where('doc_type', 'tax')->whereBetween('invoice_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            'law_firms_unpaid' => Expense::where('expense_group', 'foreign')->where('status', 'Unpaid')->count(),
            'offices_unpaid' => Expense::where('expense_group', 'patent_office')->where('status', 'Unpaid')->count(),
            'my_requests' => Invoice::where('payment_initiation->requested_by', $this->user->id)->where('payment_initiation->status', 'Pending')->count()
                + Expense::where('payment_request->requested_by', $this->user->id)->where('payment_request->status', 'Pending')->count(),
        ];
    }

    /** Last six months: tax invoices issued (count) – a currency-neutral activity trend. */
    public function trend(): array
    {
        $rows = [];
        for ($m = 5; $m >= 0; $m--) {
            $month = now()->startOfMonth()->subMonths($m);
            $rows[] = [
                'label' => $month->format('M'),
                'tax' => Invoice::where('doc_type', 'tax')->whereBetween('invoice_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])->count(),
                'proforma' => Invoice::where('doc_type', 'proforma')->whereNotNull('sent_at')->whereBetween('sent_at', [$month, $month->copy()->endOfMonth()])->count(),
            ];
        }
        $max = max(1, ...array_map(fn ($r) => max($r['tax'], $r['proforma']), $rows));

        return ['rows' => $rows, 'max' => $max];
    }

    private function sorted(array $out): array
    {
        uksort($out, fn ($a, $b) => array_search($a, ['USD', 'EUR', 'INR']) <=> array_search($b, ['USD', 'EUR', 'INR']));

        return $out;
    }
}
