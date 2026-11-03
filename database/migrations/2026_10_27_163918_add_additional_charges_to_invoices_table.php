<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('additional_charges')->nullable();
            $table->decimal('additional_charges_total', 16, 2)->default(0);
            $table->string('disbursement_label', 80)->default('Other charges');
        });
        Schema::table('invoice_conversions', function (Blueprint $table) {
            $table->json('original_charges')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_conversions', function (Blueprint $table) {
            $table->dropColumn('original_charges');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['additional_charges', 'additional_charges_total', 'disbursement_label']);
        });
    }
};
