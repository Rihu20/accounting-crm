<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoiceRegisterService
{
    public function save(Invoice $invoice, array $data, User $actor): void
    {
        abort_unless($actor->is_active && $actor->allowed('accounting.invoices.edit'), 403);
        $data = Validator::make($data, [
            'lock_version' => 'required|integer|min:0',
            'register_user' => 'nullable|string|max:255',
            'inr_exchange_rate' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,8})?$/', 'gt:0', 'max:99999999999'],
            'sender_name' => 'nullable|string|max:255',
            'register_comment' => 'nullable|string|max:4000',
            'tds_receivable' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'],
            'payment_intimation_date' => 'nullable|date',
            'payment_informed_by' => 'nullable|string|max:255',
        ])->validate();
        DB::transaction(function () use ($invoice, $data): void {
            $current = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ((int) $current->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This row changed. Close and reopen Register details.']);
            }
            unset($data['lock_version']);
            if (array_key_exists('tds_receivable', $data)) {
                $data['tds_receivable'] ??= '0';
            }
            $current->update($data + ['lock_version' => $current->lock_version + 1]);
        });
    }
}
