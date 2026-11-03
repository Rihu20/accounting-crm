<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Client extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function ($client) {
            Validator::make($client->toArray(), ['ref_no' => ['nullable', 'regex:/^\d{4}$/', Rule::unique('clients', 'ref_no')->ignore($client->id)]])->validate();
            if ($client->exists && $client->isDirty('ref_no') && Matter::where('client_id', $client->id)->where('ref_no', '!=', $client->ref_no ?? '')->exists()) {
                throw ValidationException::withMessages(['ref_no' => 'This client has linked matters. Their client references must match before changing this Ref No.']);
            }
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
}
