<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Setting extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function ($s) {
            $rules = match ($s->key) {
                'gst_rate' => 'required|numeric|min:0|max:100','sequence_padding' => 'required|integer|min:4|max:10','year_logic' => 'required|in:calendar,financial','followup_days','followup_repeat_days' => 'required|integer|min:1',default => 'nullable|string|max:10000'
            };
            Validator::make(['value' => $s->value], ['value' => $rules])->validate();
        });
    }

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'received_on' => 'date', 'sent_at' => 'datetime', 'parse_result' => 'array', 'original_items' => 'array', 'extraction_json' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean', 'subtotal' => 'decimal:2', 'disbursement_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'amount' => 'decimal:2', 'qty' => 'decimal:2', 'rate' => 'decimal:2', 'tax_rate' => 'decimal:2', 'source_amount' => 'decimal:2', 'converted_amount' => 'decimal:2', 'charges_deducted' => 'decimal:2', 'taxable' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public static function get(string $key, $default = null)
    {
        return static::where('key', $key)->value('value') ?? $default;
    }
}
