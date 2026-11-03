<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Accountant', 'Senior Accountant', 'Head of Department', 'Director'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Schema::create('payroll_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('employee');
            $table->date('period');
            $table->decimal('gross', 14, 2);
            $table->decimal('deductions', 14, 2);
            $table->date('paid_on')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('invoice_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->string('number')->unique();
            $table->string('type');
            $table->date('note_date');
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->json('payment_initiation')->nullable();
            $table->json('tax_authorization')->nullable();
        });
        Schema::table('expenses', function (Blueprint $table): void {
            $table->json('payment_request')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_notes');
        Schema::dropIfExists('payroll_entries');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['payment_initiation', 'tax_authorization']));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('payment_request'));
    }
};
