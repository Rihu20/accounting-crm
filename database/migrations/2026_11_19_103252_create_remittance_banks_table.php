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
        Schema::create('remittance_banks', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('branch')->nullable();
            $table->string('account_no');
            $table->string('currency', 3);
            $table->string('account_type')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('a2_debit_bank_id')->nullable()->constrained('remittance_banks')->nullOnDelete();
            $table->foreignId('a2_charges_bank_id')->nullable()->constrained('remittance_banks')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('a2_debit_bank_id');
            $table->dropConstrainedForeignId('a2_charges_bank_id');
        });
        Schema::dropIfExists('remittance_banks');
    }
};
