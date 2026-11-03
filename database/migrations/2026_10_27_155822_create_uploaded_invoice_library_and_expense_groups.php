<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patent_offices', function (Blueprint $t) {
            $t->id();
            $t->string('name', 512);
            $t->string('country', 120);
            $t->string('acronym', 40)->nullable();
            $t->string('source_url', 512)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unique(['country', 'name']);
            $t->timestamps();
        });
        Schema::create('invoice_documents', function (Blueprint $t) {
            $t->id();
            $t->string('doc_type');
            $t->string('invoice_no')->nullable();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->date('invoice_date')->nullable();
            $t->unsignedSmallInteger('period_year')->nullable();
            $t->unsignedTinyInteger('period_month')->nullable();
            $t->string('file_name');
            $t->string('path');
            $t->string('sha256', 64)->unique();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->text('notes')->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->timestamps();
        });
        Schema::table('expenses', function (Blueprint $t) {
            $t->string('expense_group')->default('indian')->index();
            $t->foreignId('patent_office_id')->nullable()->constrained()->nullOnDelete();
            $t->string('payee_name', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('patent_office_id');
            $t->dropColumn(['expense_group', 'payee_name']);
        });
        Schema::dropIfExists('invoice_documents');
        Schema::dropIfExists('patent_offices');
    }
};
