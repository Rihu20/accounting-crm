<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A Form A2 that was prepared, with what happened to the remittance after it. The filled values are kept so the same Word file can be opened again. */
class RemittanceForm extends Model
{
    public const STATUSES = ['Prepared' => 'gray', 'Submitted' => 'warning', 'Remitted' => 'success', 'Cancelled' => 'danger'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'amount' => 'decimal:2', 'exchange_rate' => 'decimal:4', 'inr_debited' => 'decimal:2', 'bank_charges_inr' => 'decimal:2', 'submitted_on' => 'date', 'remitted_on' => 'date', 'form_15ca_date' => 'date', 'form_15cb_date' => 'date'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['Prepared', 'Submitted'], true);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** Every vendor bill paid by this remittance. */
    public function expenses(): BelongsToMany
    {
        return $this->belongsToMany(Expense::class, 'remittance_form_expense');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function debitBank(): BelongsTo
    {
        return $this->belongsTo(RemittanceBank::class, 'debit_bank_id');
    }

    public function chargesBank(): BelongsTo
    {
        return $this->belongsTo(RemittanceBank::class, 'charges_bank_id');
    }
}
