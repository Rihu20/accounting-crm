<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class InvoiceNote extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['invoice_id', 'number', 'type', 'note_date', 'amount', 'reason', 'created_by', 'currency', 'taxable_amount', 'non_taxable_amount', 'gst_rate', 'igst', 'cgst', 'sgst', 'pdf_path'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return ['note_date' => 'date', 'amount' => 'decimal:2', 'taxable_amount' => 'decimal:2', 'non_taxable_amount' => 'decimal:2', 'gst_rate' => 'decimal:2', 'igst' => 'decimal:2', 'cgst' => 'decimal:2', 'sgst' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return $this->type === 'debit' ? 'Debit Note' : 'Credit Note';
    }

    public function gstTotal(): string
    {
        return bcadd(bcadd((string) $this->igst, (string) $this->cgst, 2), (string) $this->sgst, 2);
    }
}
