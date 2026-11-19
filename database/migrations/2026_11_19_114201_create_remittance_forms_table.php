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
        Schema::create('remittance_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('beneficiary');
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->foreignId('debit_bank_id')->nullable()->constrained('remittance_banks')->nullOnDelete();
            $table->foreignId('charges_bank_id')->nullable()->constrained('remittance_banks')->nullOnDelete();
            $table->string('file_name');
            $table->json('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remittance_forms');
    }
};
