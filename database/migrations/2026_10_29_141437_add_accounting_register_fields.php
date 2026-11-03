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
            $table->string('register_user')->nullable();
            $table->decimal('inr_exchange_rate', 20, 8)->nullable();
            $table->string('sender_name')->nullable();
            $table->text('register_comment')->nullable();
            $table->decimal('tds_receivable', 14, 2)->default(0);
            $table->date('payment_intimation_date')->nullable();
            $table->string('payment_informed_by')->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('sender_name')->nullable();
            $table->text('payment_advice')->nullable();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('party_country')->nullable();
            $table->string('our_ref')->nullable();
            $table->text('purpose')->nullable();
            $table->string('pan_number', 20)->nullable();
            $table->string('gstin', 30)->nullable();
            $table->decimal('taxable_amount', 14, 2)->nullable();
            $table->string('gst_mode')->default('igst');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->string('tds_section', 40)->nullable();
            $table->decimal('tds_rate', 5, 2)->default(0);
            $table->string('payment_bank_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['register_user', 'inr_exchange_rate', 'sender_name', 'register_comment', 'tds_receivable', 'payment_intimation_date', 'payment_informed_by']));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['sender_name', 'payment_advice']));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn(['party_country', 'our_ref', 'purpose', 'pan_number', 'gstin', 'taxable_amount', 'gst_mode', 'gst_rate', 'igst', 'cgst', 'sgst', 'tds_section', 'tds_rate', 'payment_bank_name']));
    }
};
