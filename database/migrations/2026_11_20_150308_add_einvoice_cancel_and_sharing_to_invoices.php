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
            $table->string('irn', 64)->nullable();
            $table->string('irn_ack_no')->nullable();
            $table->dateTime('irn_ack_date')->nullable();
            $table->text('irn_signed_qr')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('client_sent_at')->nullable();
            $table->string('client_sent_to')->nullable();
            $table->unsignedBigInteger('copied_from_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['irn', 'irn_ack_no', 'irn_ack_date', 'irn_signed_qr', 'cancel_reason', 'cancelled_at', 'client_sent_at', 'client_sent_to', 'copied_from_id']));
    }
};
