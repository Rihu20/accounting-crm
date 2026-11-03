<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatentOffice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getDisplayNameAttribute(): string
    {
        return ($this->acronym ? $this->acronym.' - ' : '').$this->name.' ('.$this->country.')';
    }
}
