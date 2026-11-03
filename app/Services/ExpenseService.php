<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function paid(Expense $expense, array $data, User $actor): void
    {
        abort_unless($actor->allowed('accounting.expenses.pay'), 403);
        $data = Validator::make($data, ['paid_on' => 'required|date', 'paid_ref' => 'required|string|max:255', 'payment_bank_name' => 'nullable|string|max:255'])->validate();
        DB::transaction(function () use ($expense, $data): void {
            $current = Expense::lockForUpdate()->findOrFail($expense->id);
            if ($current->expense_type !== 'vendor' || $current->status !== 'Unpaid') {
                throw ValidationException::withMessages(['paid_ref' => 'This expense is already paid or its status changed. Reopen the expense list.']);
            }
            $request = $current->payment_request;
            if (($request['status'] ?? null) === 'Pending') {
                // Director / Accountant paying it is the approval.
                $request = array_replace($request, ['status' => 'Approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()->toDateTimeString()]);
            }
            $current->update($data + ['status' => 'Paid', 'payment_request' => $request, 'lock_version' => $current->lock_version + 1]);
        });
    }

    public function unpaid(Expense $expense, string $reason, User $actor): void
    {
        abort_unless($actor->allowed('accounting.expenses.pay'), 403);
        abort_if(trim($reason) === '', 422, 'A reason is required');
        DB::transaction(function () use ($expense, $reason, $actor): void {
            $current = Expense::lockForUpdate()->findOrFail($expense->id);
            abort_unless($current->expense_type === 'vendor' && $current->status === 'Paid', 422);
            $current->update(['status' => 'Unpaid', 'paid_on' => null, 'paid_ref' => null, 'payment_bank_name' => null, 'lock_version' => $current->lock_version + 1]);
            activity()->causedBy($actor)->performedOn($current)->withProperties(['reason' => $reason])->log('Expense marked unpaid');
        });
    }

    public function reimburse(Expense $expense, array $data, User $actor): void
    {
        abort_unless($actor->allowed('accounting.expenses.pay'), 403);
        $data = Validator::make($data, ['reimbursement_status' => 'required|in:Reimbursed,Not reimbursable', 'reimbursed_on' => 'required|date', 'reimbursed_ref' => 'required|string|max:255'])->validate();
        DB::transaction(function () use ($expense, $data): void {
            $current = Expense::lockForUpdate()->findOrFail($expense->id);
            if ($current->expense_type !== 'owner' || $current->reimbursement_status !== 'Pending') {
                throw ValidationException::withMessages(['reimbursed_ref' => 'Reimbursement status already changed. Reopen the expense list.']);
            }
            $current->update($data + ['lock_version' => $current->lock_version + 1]);
        });
    }
}
