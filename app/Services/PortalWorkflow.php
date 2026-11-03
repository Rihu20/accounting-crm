<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Requests and approvals:
 *  - Paralegal  -> "client has initiated payment" on a proforma / tax invoice
 *  - Paralegal  -> "please pay this law firm / patent office" on an expense
 *  - Assistant  -> "please approve the tax invoice" on a proforma
 * Director or Accountant review all of them. The Assistant never approves.
 */
class PortalWorkflow
{
    public const PARALEGAL_GROUPS = ['foreign', 'patent_office'];

    public static function canAuthorizeTax(User $actor): bool
    {
        return $actor->allowed('accounting.tax.approve');
    }

    public static function needsTaxApproval(User $actor): bool
    {
        return ! self::canAuthorizeTax($actor);
    }

    public static function canReviewRequests(User $actor): bool
    {
        return $actor->allowed('requests.review');
    }

    public static function canReviewExpenses(User $actor): bool
    {
        return self::canReviewRequests($actor);
    }

    public static function paralegalExpenses(Builder $query): Builder
    {
        return $query->whereIn('expense_group', self::PARALEGAL_GROUPS);
    }

    public static function assignedExpenses(Builder $query, User $actor): Builder
    {
        return self::paralegalExpenses($query);
    }

    /** Assistant asks Director / Accountant to approve the tax invoice for a proforma. */
    public function requestTax(Invoice $invoice, User $actor): void
    {
        abort_unless($actor->allowed('accounting.tax.request'), 403);
        DB::transaction(function () use ($invoice, $actor): void {
            $current = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless($current->doc_type === 'proforma' && in_array($current->status, ['Sent', 'Partially received', 'Received']), 422, 'Only a sent proforma can be put up for a tax invoice.');
            abort_if(in_array($current->tax_authorization['status'] ?? null, ['Pending', 'Approved']), 422, 'A tax invoice request already exists.');
            $current->update(['tax_authorization' => $this->request($actor)]);
        });
        app(NotifyService::class)->approvers('Tax invoice approval requested', ($invoice->number ?? 'Proforma').' – '.$actor->name.' has asked for the tax invoice.', '/admin/invoices?tab=approvals');
    }

    /** Director / Accountant approve (with or without a prior request) or reject. */
    public function decideTax(Invoice $invoice, User $actor, bool $approved, ?string $note = null): void
    {
        abort_unless(self::canAuthorizeTax($actor), 403);
        DB::transaction(function () use ($invoice, $actor, $approved, $note): void {
            $current = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless($current->doc_type === 'proforma' && in_array($current->status, ['Sent', 'Partially received', 'Received']), 422);
            if ($approved) {
                abort_unless($current->status === 'Received', 422, 'Full payment must be recorded before the tax invoice is approved.');
            }
            $base = $current->tax_authorization ?: $this->request($actor);
            $current->update(['tax_authorization' => $this->decision($base, $actor, $approved) + ['note' => $note]]);
        });
        app(NotifyService::class)->role(PlatformAccess::ASSISTANT, $approved ? 'Tax invoice approved' : 'Tax invoice request rejected', ($invoice->number ?? 'Proforma').($approved ? ' is approved – generate the tax invoice.' : ' was not approved'.($note ? ': '.$note : '.')), '/admin/invoices?tab=ready');
    }

    public function reportInvoicePayment(Invoice $invoice, User $actor, string $reference): void
    {
        abort_unless($actor->allowed('requests.create'), 403);
        DB::transaction(function () use ($invoice, $actor, $reference): void {
            $current = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless(in_array($current->status, ['Sent', 'Partially received', 'Issued']) && ($current->payment_initiation['status'] ?? null) !== 'Pending', 422, 'A payment report is already waiting for review.');
            $current->update(['payment_initiation' => $this->request($actor) + ['reference' => $reference]]);
        });
        app(NotifyService::class)->approvers('Client payment initiated', $actor->name.' reports payment initiated for '.($invoice->number ?? 'an invoice').': '.$reference, '/admin/invoices?tab=approvals');
    }

    public function reviewInvoicePayment(Invoice $invoice, User $actor, bool $approved): void
    {
        abort_unless(self::canReviewRequests($actor), 403);
        DB::transaction(function () use ($invoice, $actor, $approved): void {
            $current = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless(($current->payment_initiation['status'] ?? null) === 'Pending', 422);
            $current->update(['payment_initiation' => $this->decision($current->payment_initiation, $actor, $approved)]);
        });
    }

    public function requestExpense(Expense $expense, User $actor, ?string $note): void
    {
        abort_unless($actor->allowed('requests.create'), 403);
        DB::transaction(function () use ($expense, $actor, $note): void {
            $current = self::paralegalExpenses(Expense::query())->lockForUpdate()->findOrFail($expense->id);
            abort_unless($current->status === 'Unpaid', 422, 'This expense is already paid.');
            abort_if(in_array($current->payment_request['status'] ?? null, ['Pending', 'Approved']), 422, 'A payment request already exists.');
            $current->update(['payment_request' => $this->request($actor) + ['note' => $note], 'lock_version' => $current->lock_version + 1]);
        });
        app(NotifyService::class)->approvers('Payment requested', $actor->name.' asks to pay '.($expense->vendor?->name ?? $expense->payee_name ?? 'a vendor').' invoice '.($expense->vendor_invoice_no ?? ''), '/admin/expenses?tab=approvals');
    }

    public function decideExpense(Expense $expense, User $actor, bool $approved): void
    {
        abort_unless(self::canReviewRequests($actor), 403);
        DB::transaction(function () use ($expense, $actor, $approved): void {
            $current = Expense::lockForUpdate()->findOrFail($expense->id);
            abort_unless($current->status === 'Unpaid' && ($current->payment_request['status'] ?? null) === 'Pending', 422);
            $current->update(['payment_request' => $this->decision($current->payment_request, $actor, $approved), 'lock_version' => $current->lock_version + 1]);
        });
    }

    private function request(User $actor): array
    {
        return ['status' => 'Pending', 'requested_by' => $actor->id, 'requested_by_name' => $actor->name, 'requested_at' => now()->toDateTimeString()];
    }

    private function decision(array $request, User $actor, bool $approved): array
    {
        return array_replace($request, [
            'status' => $approved ? 'Approved' : 'Rejected',
            'reviewed_by' => $actor->id,
            'reviewed_by_name' => $actor->name,
            'reviewed_at' => now()->toDateTimeString(),
        ]);
    }
}
