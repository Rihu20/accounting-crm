<?php

namespace App\Models;

use App\Services\ExpenseGroups;
use App\Services\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Expense extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Expense $expense): void {
            if (! $expense->expense_group) {
                $expense->expense_group = $expense->vendor?->vendor_type === 'Foreign' ? 'foreign' : 'indian';
            }
            if (! array_key_exists($expense->expense_group, ExpenseGroups::LABELS)) {
                throw ValidationException::withMessages(['expense_group' => 'Select a valid expense group.']);
            }
            if ($expense->expense_group === 'patent_office') {
                $office = PatentOffice::find($expense->patent_office_id);
                if (! $office || (! $office->is_active && ($expense->isDirty('patent_office_id') || ! $expense->exists))) {
                    throw ValidationException::withMessages(['patent_office_id' => 'Select a patent / IP office.']);
                }
                $expense->payee_name = $office->name;
            } else {
                $expense->patent_office_id = null;
            }
            if ($expense->expense_group === 'indian' && $expense->taxable_amount !== null) {
                Validator::make($expense->getAttributes(), [
                    'currency' => 'required|in:INR',
                    'taxable_amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0.01', 'max:999999999999.99'],
                    'gst_rate' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'between:0,100'],
                    'gst_mode' => 'required|in:igst,cgst_sgst',
                    'tds_rate' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'between:0,100'],
                ])->validate();
                $base = (string) $expense->taxable_amount;
                $rate = bcdiv((string) $expense->gst_rate, '100', 8);
                $expense->igst = $expense->gst_mode === 'igst' ? Money::mul($base, $rate) : '0.00';
                $expense->cgst = $expense->gst_mode === 'cgst_sgst' ? Money::mul($base, bcdiv($rate, '2', 8)) : '0.00';
                $expense->sgst = $expense->cgst;
                $expense->gst_amount = bcadd(bcadd($expense->igst, $expense->cgst, 2), $expense->sgst, 2);
                $expense->tds_amount = Money::mul($base, bcdiv((string) $expense->tds_rate, '100', 8));
                $expense->tds_applicable = bccomp($expense->tds_amount, '0', 2) > 0;
                $expense->amount = bcsub(bcadd($base, $expense->gst_amount, 2), $expense->tds_amount, 2);
            }
        });
    }

    public function patentOffice(): BelongsTo
    {
        return $this->belongsTo(PatentOffice::class);
    }

    protected function casts(): array
    {
        // The GST and TDS columns were the only money on an expense that came back unformatted.
        return ['payment_request' => 'array', 'invoice_date' => 'date', 'due_date' => 'date', 'received_on' => 'date', 'sent_at' => 'datetime', 'parse_result' => 'array', 'original_items' => 'array', 'extraction_json' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean', 'subtotal' => 'decimal:2', 'disbursement_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'amount' => 'decimal:2', 'qty' => 'decimal:2', 'rate' => 'decimal:2', 'tax_rate' => 'decimal:2', 'source_amount' => 'decimal:2', 'converted_amount' => 'decimal:2', 'charges_deducted' => 'decimal:2', 'taxable' => 'boolean', 'taxable_amount' => 'decimal:2', 'gst_amount' => 'decimal:2', 'igst' => 'decimal:2', 'cgst' => 'decimal:2', 'sgst' => 'decimal:2', 'tds_amount' => 'decimal:2', 'tds_applicable' => 'boolean', 'requires_15ca_cb' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
