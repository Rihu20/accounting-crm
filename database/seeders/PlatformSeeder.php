<?php

namespace Database\Seeders;

use App\Services\PlatformAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PlatformSeeder extends Seeder
{
    /** Four fixed roles. What each role may do is defined in PlatformAccess::matrix(). */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (PlatformAccess::ROLES as $name) {
            Role::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
