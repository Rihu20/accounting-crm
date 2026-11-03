<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

abstract class CrmResource extends Resource
{
    public static function permissionPrefix(): string
    {
        return match (class_basename(static::getModel())) {
            'User' => 'platform.users', 'Role' => 'platform.roles',
            'Setting', 'BankAccount', 'PaymentGateway' => 'accounting.settings',
            default => 'accounting.masters',
        };
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->allowed(static::permissionPrefix().(in_array(static::permissionPrefix(), ['platform.users', 'platform.roles', 'accounting.settings']) ? '.manage' : '.view')) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->allowed(static::permissionPrefix().(static::permissionPrefix() === 'accounting.masters' ? '.create' : '.manage')) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->allowed(static::permissionPrefix().(static::permissionPrefix() === 'accounting.masters' ? '.edit' : '.manage')) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
