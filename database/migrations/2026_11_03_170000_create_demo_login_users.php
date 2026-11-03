<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dummy logins for testing each role. Before going live: Settings → Users → switch these off (Active = No).
 */
return new class extends Migration
{
    private const USERS = [
        ['accountant@demo.test', 'Demo Accountant', 'Accountant'],
        ['assistant@demo.test', 'Demo Assistant', 'Accountant Assistant'],
        ['director@demo.test', 'Demo Director', 'Director'],
        ['paralegal@demo.test', 'Demo Paralegal', 'Paralegal'],
    ];

    public function up(): void
    {
        // Only for an install that is already set up (never on a fresh install or in tests).
        $hasAccountant = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'Accountant')->exists();
        if (app()->runningUnitTests() || ! $hasAccountant) {
            return;
        }
        foreach (self::USERS as [$email, $name, $role]) {
            if (! DB::table('users')->where('email', $email)->exists()) {
                DB::table('users')->insert(['name' => $name, 'email' => $email, 'password' => Hash::make('Demo@12345'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $userId = DB::table('users')->where('email', $email)->value('id');
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if ($roleId && ! DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->where('model_id', $userId)->exists()) {
                DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => 'App\\Models\\User', 'model_id' => $userId]);
            }
        }
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        $ids = DB::table('users')->whereIn('email', array_column(self::USERS, 0))->pluck('id');
        DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->whereIn('model_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->delete();
    }
};
