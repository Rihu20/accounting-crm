<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Payment extends Model
{
    use LogsActivity;

    protected $guarded = [];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'received_on' => 'date', 'sent_at' => 'datetime', 'parse_result' => 'array', 'original_items' => 'array', 'extraction_json' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean', 'subtotal' => 'decimal:2', 'disbursement_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'amount' => 'decimal:2', 'qty' => 'decimal:2', 'rate' => 'decimal:2', 'tax_rate' => 'decimal:2', 'source_amount' => 'decimal:2', 'converted_amount' => 'decimal:2', 'charges_deducted' => 'decimal:2', 'tds_amount' => 'decimal:2', 'write_off_amount' => 'decimal:2', 'taxable' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
