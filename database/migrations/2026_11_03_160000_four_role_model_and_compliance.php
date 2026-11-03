<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEEP = ['Accountant', 'Accountant Assistant', 'Director', 'Paralegal'];

    public function up(): void
    {
        // ---- 1. Payments: TDS deducted by Indian clients and short-payment write-off ----
        Schema::table('payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('payments', 'tds_amount')) {
                $table->decimal('tds_amount', 14, 2)->default(0);
            }
            if (! Schema::hasColumn('payments', 'write_off_amount')) {
                $table->decimal('write_off_amount', 14, 2)->default(0);
            }
            if (! Schema::hasColumn('payments', 'entry_type')) {
                $table->string('entry_type', 20)->default('receipt');
            }
        });

        // ---- 2. Credit / Debit notes with GST, auto-number and PDF ----
        Schema::table('invoice_notes', function (Blueprint $table): void {
            foreach ([
                'currency' => fn () => $table->string('currency', 3)->nullable(),
                'taxable_amount' => fn () => $table->decimal('taxable_amount', 14, 2)->default(0),
                'non_taxable_amount' => fn () => $table->decimal('non_taxable_amount', 14, 2)->default(0),
                'gst_rate' => fn () => $table->decimal('gst_rate', 5, 2)->default(0),
                'igst' => fn () => $table->decimal('igst', 14, 2)->default(0),
                'cgst' => fn () => $table->decimal('cgst', 14, 2)->default(0),
                'sgst' => fn () => $table->decimal('sgst', 14, 2)->default(0),
                'pdf_path' => fn () => $table->string('pdf_path')->nullable(),
            ] as $column => $add) {
                if (! Schema::hasColumn('invoice_notes', $column)) {
                    $add();
                }
            }
        });
        DB::table('invoice_notes')->whereNull('currency')->orderBy('id')->each(function ($note): void {
            $currency = DB::table('invoices')->where('id', $note->invoice_id)->value('currency');
            DB::table('invoice_notes')->where('id', $note->id)->update(['currency' => $currency, 'non_taxable_amount' => $note->amount]);
        });

        // ---- 3. Clients: state code for place of supply ----
        if (! Schema::hasColumn('clients', 'state_code')) {
            Schema::table('clients', fn (Blueprint $table) => $table->string('state_code', 2)->nullable());
        }

        // ---- 4. Settings: GST invoice serials run per financial year; LUT for exports ----
        DB::table('settings')->updateOrInsert(['key' => 'year_logic'], ['value' => 'financial', 'updated_at' => now()]);
        foreach (['lut_arn' => '', 'lut_valid_upto' => '', 'credit_note_prefix' => 'CN', 'debit_note_prefix' => 'DN'] as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // ---- 5. Four fixed roles ----
        $this->convertRoles();
    }

    private function convertRoles(): void
    {
        $roles = DB::table('roles');
        $id = fn (string $name) => DB::table('roles')->where('name', $name)->where('guard_name', 'web')->value('id');
        $ensure = function (string $name) use ($id): int {
            if (! $id($name)) {
                DB::table('roles')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }

            return (int) $id($name);
        };

        // The old "Accountant" role needed approval for tax invoices: that is the new Assistant.
        $oldAccountant = $id('Accountant');
        if ($oldAccountant && ! $id('Accountant Assistant')) {
            DB::table('roles')->where('id', $oldAccountant)->update(['name' => 'Accountant Assistant']);
        }
        $accountant = $ensure('Accountant');
        $assistant = $ensure('Accountant Assistant');
        $director = $ensure('Director');
        $paralegal = $ensure('Paralegal');

        $map = [
            'Admin' => $accountant, 'Senior Accountant' => $accountant, 'Head of Department' => $accountant,
            'Manager' => $accountant, 'Accounts Team' => $assistant,
            'Payment Approver' => $director, 'Owner' => $director,
        ];
        $priority = [$accountant => 1, $director => 2, $assistant => 3, $paralegal => 4];
        $userType = 'App\\Models\\User';

        $assignments = DB::table('model_has_roles')->where('model_type', $userType)->get()->groupBy('model_id');
        foreach ($assignments as $userId => $rows) {
            $targets = [];
            foreach ($rows as $row) {
                $name = DB::table('roles')->where('id', $row->role_id)->value('name');
                if (isset($map[$name])) {
                    $targets[] = $map[$name];
                } elseif (in_array($name, self::KEEP, true)) {
                    $targets[] = (int) $row->role_id;
                }
            }
            DB::table('model_has_roles')->where('model_type', $userType)->where('model_id', $userId)->delete();
            if ($targets) {
                usort($targets, fn ($a, $b) => $priority[$a] <=> $priority[$b]);
                DB::table('model_has_roles')->insert(['role_id' => $targets[0], 'model_type' => $userType, 'model_id' => $userId]);
            }
        }

        // Placeholder approver accounts that were never activated or used.
        foreach (DB::table('users')->where('email', 'like', 'approver%@crm.invalid')->where('is_active', false)->pluck('id') as $placeholder) {
            if (! DB::table('payments')->where('marked_by', $placeholder)->exists()) {
                DB::table('model_has_roles')->where('model_type', $userType)->where('model_id', $placeholder)->delete();
                DB::table('users')->where('id', $placeholder)->delete();
            }
        }

        // Permissions now live in code (PlatformAccess::matrix). Drop the old grants and roles.
        DB::table('role_has_permissions')->delete();
        DB::table('model_has_permissions')->delete();
        $obsolete = DB::table('roles')->whereNotIn('name', self::KEEP)->pluck('id');
        DB::table('model_has_roles')->whereIn('role_id', $obsolete)->delete();
        DB::table('roles')->whereIn('id', $obsolete)->delete();
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['tds_amount', 'write_off_amount', 'entry_type']));
        Schema::table('invoice_notes', fn (Blueprint $table) => $table->dropColumn(['currency', 'taxable_amount', 'non_taxable_amount', 'gst_rate', 'igst', 'cgst', 'sgst', 'pdf_path']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('state_code'));
    }
};
