<?php

namespace App\Models;

use App\Services\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Invoice extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice): void {
            $data = Validator::make([
                'additional_charges' => $invoice->additional_charges,
                'disbursement_label' => $invoice->disbursement_label,
            ], [
                'additional_charges' => 'nullable|array|max:10',
                'additional_charges.*.label' => 'required|string|max:80',
                'additional_charges.*.amount' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/|min:0|max:999999999999.99',
                'disbursement_label' => 'nullable|string|max:80',
            ])->validate();
            $invoice->additional_charges = array_values(array_map(fn ($charge) => [
                'label' => trim($charge['label']),
                'amount' => Money::round((string) $charge['amount']),
            ], $data['additional_charges'] ?? []));
            $invoice->disbursement_label = trim($invoice->disbursement_label ?? '') ?: 'Other charges';
        });
    }

    protected function casts(): array
    {
        return ['payment_initiation' => 'array', 'tax_authorization' => 'array', 'payment_intimation_date' => 'date', 'inr_exchange_rate' => 'decimal:8', 'tds_receivable' => 'decimal:2', 'additional_charges' => 'array', 'additional_charges_total' => 'decimal:2', 'invoice_date' => 'date', 'due_date' => 'date', 'received_on' => 'date', 'sent_at' => 'datetime', 'parse_result' => 'array', 'original_items' => 'array', 'extraction_json' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean', 'subtotal' => 'decimal:2', 'disbursement_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'amount' => 'decimal:2', 'qty' => 'decimal:2', 'rate' => 'decimal:2', 'tax_rate' => 'decimal:2', 'source_amount' => 'decimal:2', 'converted_amount' => 'decimal:2', 'charges_deducted' => 'decimal:2', 'taxable' => 'boolean'];
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

    public function items()
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function receivedAmount(): string
    {
        $paymentInvoiceId = $this->doc_type === 'tax' && $this->proforma_id ? $this->proforma_id : $this->id;

        return (string) Payment::where('invoice_id', $paymentInvoiceId)->where('reversed', false)->sum('amount');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function proforma()
    {
        return $this->belongsTo(self::class, 'proforma_id');
    }

    public function taxInvoice()
    {
        return $this->hasOne(self::class, 'proforma_id');
    }

    public function registerPayments(): Collection
    {
        return ($this->doc_type === 'tax' && $this->proforma_id ? $this->proforma?->payments : $this->payments)?->where('reversed', false)->sortByDesc('received_on')->values() ?? collect();
    }

    public function registerPaymentTotal(string $field = 'amount'): string
    {
        return $this->registerPayments()->reduce(fn (string $sum, Payment $payment) => bcadd($sum, (string) ($payment->{$field} ?? '0'), 2), '0.00');
    }

    /** Cash received + TDS deducted by the client + written-off short payment. */
    public function settledAmount(): string
    {
        return bcadd(bcadd($this->registerPaymentTotal('amount'), $this->registerPaymentTotal('tds_amount'), 2), $this->registerPaymentTotal('write_off_amount'), 2);
    }

    public function outstandingAmount(): string
    {
        return bcsub((string) $this->grand_total, $this->settledAmount(), 2);
    }

    public function isNote(): bool
    {
        return false;
    }

    public function notes()
    {
        return $this->hasMany(InvoiceNote::class);
    }

    public function registerInrAmount(): ?string
    {
        if ($this->currency === 'INR') {
            return $this->grand_total;
        }

        return $this->inr_exchange_rate ? Money::mul($this->grand_total, (string) $this->inr_exchange_rate) : null;
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    public function conversions()
    {
        return $this->hasMany(InvoiceConversion::class);
    }
}
