<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PayrollEntry extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['employee', 'period', 'gross', 'deductions', 'paid_on', 'created_by'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return ['period' => 'date', 'paid_on' => 'date', 'gross' => 'decimal:2', 'deductions' => 'decimal:2'];
    }

    public function net(): string
    {
        return bcsub($this->gross, $this->deductions, 2);
    }
}
