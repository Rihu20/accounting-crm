<?php

namespace App\Models;

use App\Services\MatterReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Matter extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function ($m) {
            $m->our_ref = filled($m->our_ref) ? strtoupper(trim($m->our_ref)) : null;
            $parts = MatterReference::parse($m->our_ref);
            if ($parts) {
                if (filled($m->ref_no) && $m->ref_no !== $parts['client_ref']) {
                    throw ValidationException::withMessages(['ref_no' => 'Client Ref No must match the four digits inside the full matter reference.']);
                }
                $m->ref_no = $parts['client_ref'];
            }
            if (! $m->exists && ! $parts) {
                throw ValidationException::withMessages(['our_ref' => 'Enter a full matter reference such as P25124809KR.']);
            }
            $client = Client::find($m->client_id);
            if ($client?->ref_no && $client->ref_no !== $m->ref_no) {
                throw ValidationException::withMessages(['ref_no' => 'This Ref No belongs to a different client.']);
            }
            if ($parts && $client && ! $client->ref_no) {
                $client->update(['ref_no' => $parts['client_ref']]);
            }
            Validator::make($m->toArray(), ['ref_no' => ['required', 'regex:/^\d{4}$/'], 'client_id' => 'required|exists:clients,id', 'paralegal_id' => 'required|exists:paralegals,id', 'service_type' => 'required|in:Official Fees,Professional Fees,Translation'])->validate();
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

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function paralegal()
    {
        return $this->belongsTo(Paralegal::class);
    }
}
