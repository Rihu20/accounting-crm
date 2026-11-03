<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'expenses'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedInteger('lock_version')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'expenses'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('lock_version'));
        }
    }
};
