<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['web_leads', 'np_estimates', 'np_country_fees', 'web_media', 'web_redirects'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Extra module contains records. Preserve them before removing its tables.');
            }
        }
        if (Schema::hasTable('web_contents') && DB::table('web_contents')->whereNotIn('slug', ['home', 'services', 'privacy'])->exists()) {
            throw new RuntimeException('Website has added content. Preserve it before removing its tables.');
        }
        foreach (['np_estimates', 'np_country_fees', 'web_leads', 'web_redirects', 'web_media', 'web_contents'] as $table) {
            Schema::dropIfExists($table);
        }
        $ids = DB::table('permissions')->where('name', 'like', 'website.%')->orWhere('name', 'like', 'leads.%')->orWhere('name', 'like', 'national_phase.%')->pluck('id');
        DB::table('permissions')->whereIn('id', $ids)->delete();
        $role = DB::table('roles')->where('name', 'Website Editor')->first();
        if ($role && ! DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
            DB::table('roles')->where('id', $role->id)->delete();
        }
    }

    public function down(): void {}
};
