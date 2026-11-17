<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('round_off', 14, 2)->default(0);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('inr_amount', 14, 2)->nullable();
        });
        foreach (['write_off_limit_percent' => '5', 'round_off_inr' => 'no'] as $key => $value) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['write_off_limit_percent', 'round_off_inr'])->delete();
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('inr_amount'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('round_off'));
    }
};
