<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Form A2 that was prepared. The filled values are kept so the same Word file can be opened again at any time. */
class RemittanceForm extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'amount' => 'decimal:2'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
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
