<?php

namespace App\Models;

use App\Services\PlatformAccess;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasFactory, HasRoles, Notifiable, \Spatie\Activitylog\Models\Concerns\LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'email', 'is_active'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected $guarded = [];

    protected $attributes = ['is_active' => true];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $panel->getId() === 'admin' && $this->allowed('platform.staff.access');
    }

    /** Local initials avatar (no external image service, works offline and with the CSP). */
    public function getFilamentAvatarUrl(): ?string
    {
        $initials = collect(preg_split('/\s+/', trim((string) $this->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e53935"/><stop offset="1" stop-color="#9f1d1d"/></linearGradient></defs><rect width="64" height="64" rx="32" fill="url(#g)"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="26" font-weight="700" fill="#fff">'.e($initials ?: '?').'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function crmRole(): ?string
    {
        return PlatformAccess::role($this);
    }

    public function isRole(string ...$roles): bool
    {
        return PlatformAccess::is($this, ...$roles);
    }

    public function allowed(string $permission): bool
    {
        return PlatformAccess::allows($this, $permission);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function paralegal()
    {
        return $this->hasOne(Paralegal::class);
    }
}
