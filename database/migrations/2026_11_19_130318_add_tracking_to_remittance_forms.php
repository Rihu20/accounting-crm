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
        Schema::table('remittance_forms', function (Blueprint $table) {
            $table->string('status')->default('Prepared');
            $table->date('submitted_on')->nullable();
            $table->date('remitted_on')->nullable();
            $table->string('bank_reference')->nullable();
            $table->decimal('exchange_rate', 14, 4)->nullable();
            $table->decimal('inr_debited', 14, 2)->nullable();
            $table->decimal('bank_charges_inr', 14, 2)->nullable();
            $table->string('advice_path')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->string('form_15ca_ack')->nullable();
            $table->date('form_15ca_date')->nullable();
            $table->string('form_15cb_no')->nullable();
            $table->date('form_15cb_date')->nullable();
        });
        Schema::create('remittance_form_expense', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->unique(['remittance_form_id', 'expense_id']);
        });
        DB::table('remittance_forms')->whereNotNull('expense_id')->orderBy('id')->get(['id', 'expense_id'])
            ->each(fn ($form) => DB::table('remittance_form_expense')->insert(['remittance_form_id' => $form->id, 'expense_id' => $form->expense_id]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remittance_form_expense');
        Schema::table('remittance_forms', fn (Blueprint $table) => $table->dropColumn(['status', 'submitted_on', 'remitted_on', 'bank_reference', 'exchange_rate', 'inr_debited', 'bank_charges_inr', 'advice_path', 'cancelled_reason', 'form_15ca_ack', 'form_15ca_date', 'form_15cb_no', 'form_15cb_date']));
    }
};
