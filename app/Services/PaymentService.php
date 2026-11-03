<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Payment status is controlled by Director and Accountant only.
 * A proforma is settled when  received amount + TDS deducted + written-off short payment  >= invoice total.
 */
class PaymentService
{
    private const MONEY = ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0'];

    public function receive(Invoice $invoice, array $data, User $actor): void
    {
        abort_unless($actor->is_active && $actor->allowed('payments.mark_received'), 403);
        $data = Validator::make($data, [
            'received_on' => 'required|date',
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0'],
            'tds_amount' => self::MONEY,
            'currency' => 'required|in:USD,INR,EUR',
            'bank_account_id' => 'nullable|required_without:gateway_id|integer',
            'gateway_id' => 'nullable|required_without:bank_account_id|integer',
            'txn_ref' => 'required|string|max:255',
            'charges_deducted' => [...self::MONEY, 'lte:amount'],
            'notes' => 'nullable|string|max:4000',
            'sender_name' => 'nullable|string|max:255',
            'payment_advice' => 'nullable|string|max:4000',
        ])->validate();
        $data['charges_deducted'] = $data['charges_deducted'] ?? '0';
        $data['tds_amount'] = $data['tds_amount'] ?? '0';
        abort_unless(bccomp(bcadd((string) $data['amount'], (string) $data['tds_amount'], 2), '0', 2) > 0, 422, 'Enter the amount received or the TDS deducted.');

        DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $this->assertOpen($invoice);
            abort_unless($data['currency'] === $invoice->currency, 422, 'Payment currency must match the invoice currency');
            $settles = bcadd((string) $data['amount'], (string) $data['tds_amount'], 2);
            abort_if(bccomp(bcadd($invoice->settledAmount(), $settles, 2), (string) $invoice->grand_total, 2) > 0, 422, 'Payment + TDS exceeds the outstanding amount');
            if (! empty($data['bank_account_id'])) {
                abort_unless(BankAccount::whereKey($data['bank_account_id'])->where('currency', $invoice->currency)->where('is_active', true)->exists(), 422, 'Select an active bank in this currency');
            }
            if (! empty($data['gateway_id'])) {
                abort_unless(PaymentGateway::whereKey($data['gateway_id'])->where('is_active', true)->exists(), 422, 'Select an active payment gateway');
            }
            $invoice->payments()->create($data + ['marked_by' => $actor->id, 'entry_type' => 'receipt']);
            $this->refreshStatus($invoice);
        });
    }

    /** Close a short payment (bank charges under SHA, rounding, agreed discount) with a reason. */
    public function writeOff(Invoice $invoice, string $reason, User $actor): void
    {
        abort_unless($actor->is_active && $actor->allowed('payments.mark_received'), 403);
        abort_if(trim($reason) === '', 422, 'A reason is required');
        DB::transaction(function () use ($invoice, $reason, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $this->assertOpen($invoice);
            abort_unless($invoice->status === 'Partially received', 422, 'Only a partly paid proforma can be settled with a write-off.');
            $balance = $invoice->outstandingAmount();
            abort_unless(bccomp($balance, '0', 2) > 0, 422);
            $invoice->payments()->create(['received_on' => today(), 'amount' => '0', 'tds_amount' => '0', 'write_off_amount' => $balance, 'currency' => $invoice->currency, 'txn_ref' => 'WRITE-OFF', 'notes' => $reason, 'charges_deducted' => '0', 'marked_by' => $actor->id, 'entry_type' => 'write_off', 'bank_account_id' => $invoice->bank_account_id, 'gateway_id' => $invoice->bank_account_id ? null : $invoice->gateway_id]);
            $this->refreshStatus($invoice);
            activity()->causedBy($actor)->performedOn($invoice)->withProperties(['reason' => $reason, 'amount' => $balance])->log('Short payment written off');
        });
    }

    public function revert(Invoice $invoice, string $reason, User $actor): void
    {
        abort_unless($actor->is_active && $actor->allowed('payments.mark_received'), 403);
        abort_if(trim($reason) === '', 422, 'A reason is required');
        DB::transaction(function () use ($invoice, $reason, $actor) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless(in_array($invoice->status, ['Received', 'Partially received']), 422, 'Only an open proforma with payments can be reverted. Raise a credit note after the tax invoice.');
            $invoice->payments()->update(['reversed' => true]);
            $invoice->update(['status' => 'Sent', 'payment_status' => 'Pending', 'received_on' => null, 'tax_authorization' => null, 'lock_version' => $invoice->lock_version + 1]);
            activity()->causedBy($actor)->performedOn($invoice)->withProperties(['reason' => $reason])->log('Payment reverted');
        });
    }

    private function assertOpen(Invoice $invoice): void
    {
        abort_unless($invoice->doc_type === 'proforma' && in_array($invoice->status, ['Sent', 'Partially received']), 422, 'This proforma cannot receive a payment');
    }

    private function refreshStatus(Invoice $invoice): void
    {
        $invoice->refresh();
        $status = bccomp($invoice->settledAmount(), (string) $invoice->grand_total, 2) >= 0 ? 'Received' : 'Partially received';
        $lastReceivedOn = $invoice->payments()->where('reversed', false)->max('received_on');
        $invoice->update(['payment_status' => $status, 'status' => $status, 'received_on' => $lastReceivedOn, 'lock_version' => $invoice->lock_version + 1]);
        if ($status === 'Received') {
            app(NotifyService::class)->approvers('Payment received – approve tax invoice', 'Full payment recorded for '.$invoice->number.'. Approve the tax invoice.', '/admin/invoices?tab=approvals');
        }
    }
}
