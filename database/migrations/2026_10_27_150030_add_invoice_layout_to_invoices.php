<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->string('invoice_layout')->default('detailed'));
        DB::table('invoices')->whereIn('client_id', DB::table('clients')->where('client_type', 'Indian')->select('id'))->update(['invoice_layout' => 'india_gst']);
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('invoice_layout'));
    }
};
