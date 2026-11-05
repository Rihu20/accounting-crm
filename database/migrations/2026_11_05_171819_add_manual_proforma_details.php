<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('can_create_proforma')->default(false));
        Schema::table('invoices', function (Blueprint $table): void {
            $table->json('billing_details')->nullable();
            $table->boolean('gst_enabled')->default(true);
            $table->boolean('combine_fees')->default(false);
        });
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->string('entry_key', 36)->nullable();
            $table->string('applicant_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_create_proforma'));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn(['entry_key', 'applicant_name']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['billing_details', 'gst_enabled', 'combine_fees']));
    }
};
