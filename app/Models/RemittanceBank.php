<?php

namespace App\Models;

use Database\Factories\RemittanceBankFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Our own bank accounts used on the bank's Form A2: the account debited for the remittance and the account debited for charges. */
class RemittanceBank extends Model
{
    /** @use HasFactory<RemittanceBankFactory> */
    use HasFactory;

    use LogsActivity;

    public const ACCOUNT_TYPES = ['EEFC' => 'EEFC', 'RFC' => 'RFC', 'Current' => 'Current', 'Savings' => 'Savings', 'Other' => 'Other'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function label(): string
    {
        return $this->bank_name.($this->branch ? ', '.$this->branch : '').' · '.$this->currency.($this->account_type ? ' '.$this->account_type : '').' · '.$this->account_no;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
