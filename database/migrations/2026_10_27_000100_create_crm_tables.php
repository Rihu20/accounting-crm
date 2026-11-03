<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_active')->default(true);
            $t->string('phone')->nullable();
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('client_type');
            $t->string('country');
            $t->string('state')->nullable();
            $t->string('contact_person')->nullable();
            $t->string('email_1')->nullable();
            $t->string('email_2')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->string('gstin')->nullable();
            $t->string('default_currency', 3)->default('USD');
            $t->text('notes')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('paralegals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('name');
            $t->string('email')->nullable()->unique();
            $t->string('phone')->nullable();
            $t->string('firm')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('vendor_type');
            $t->string('country');
            $t->string('email')->nullable();
            $t->string('tax_id')->nullable();
            $t->string('default_currency', 3)->default('USD');
            $t->text('notes')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('matters', function (Blueprint $t) {
            $t->id();
            $t->string('ref_no', 6)->unique();
            $t->foreignId('client_id')->constrained();
            $t->foreignId('paralegal_id')->constrained();
            $t->string('our_ref')->nullable();
            $t->string('your_ref')->nullable();
            $t->string('matter_title');
            $t->string('country');
            $t->string('app_no')->nullable();
            $t->string('pct_no')->nullable();
            $t->string('service_type');
            $t->string('language')->nullable();
            $t->string('status')->default('Active');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('bank_name');
            $t->string('currency', 3);
            $t->string('beneficiary_name')->nullable();
            $t->text('beneficiary_address')->nullable();
            $t->string('account_no')->nullable();
            $t->string('swift')->nullable();
            $t->string('ifsc')->nullable();
            $t->text('bank_address')->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('payment_gateways', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->text('instructions')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('number_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('series');
            $t->string('year_key');
            $t->unsignedInteger('last_number')->default(0);
            $t->unique(['series', 'year_key']);
            $t->timestamps();
        });
        Schema::create('word_uploads', function (Blueprint $t) {
            $t->id();
            $t->string('file_path');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->foreignId('paralegal_id')->nullable()->constrained();
            $t->foreignId('client_id')->nullable()->constrained();
            $t->string('status');
            $t->json('parse_result');
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('doc_type')->default('proforma');
            $t->string('number')->nullable()->unique();
            $t->foreignId('client_id')->constrained();
            $t->foreignId('paralegal_id')->nullable()->constrained();
            $t->foreignId('word_upload_id')->nullable()->constrained();
            $t->foreignId('proforma_id')->nullable()->unique()->constrained('invoices');
            $t->date('invoice_date');
            $t->date('due_date');
            $t->integer('payment_term_days')->default(15);
            $t->string('currency', 3);
            $t->foreignId('bank_account_id')->nullable()->constrained();
            $t->foreignId('gateway_id')->nullable()->constrained('payment_gateways');
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('disbursement_total', 14, 2)->default(0);
            $t->decimal('tax_total', 14, 2)->default(0);
            $t->decimal('grand_total', 14, 2)->default(0);
            $t->text('amount_in_words')->nullable();
            $t->string('status')->default('Draft');
            $t->string('payment_status')->default('Pending');
            $t->date('received_on')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('pdf_path')->nullable();
            $t->boolean('print_exchange_rate')->default(false);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('matter_id')->nullable()->constrained();
            $t->string('ref_no', 6)->nullable();
            $t->string('fee_type');
            $t->text('matter_text');
            $t->string('country')->nullable();
            $t->string('app_no')->nullable();
            $t->string('pct_no')->nullable();
            $t->string('our_ref')->nullable();
            $t->string('your_ref')->nullable();
            $t->string('language')->nullable();
            $t->string('sac_code')->default('998213');
            $t->decimal('qty', 14, 2)->default(1);
            $t->decimal('rate', 14, 2);
            $t->decimal('amount', 14, 2);
            $t->boolean('taxable')->default(false);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('invoice_conversions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->string('source_currency', 3);
            $t->decimal('source_amount', 14, 2);
            $t->string('target_currency', 3);
            $t->decimal('rate', 20, 8);
            $t->date('rate_date');
            $t->string('rate_source');
            $t->decimal('converted_amount', 14, 2);
            $t->json('original_items');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3);
            $t->foreignId('bank_account_id')->nullable()->constrained();
            $t->foreignId('gateway_id')->nullable()->constrained('payment_gateways');
            $t->string('txn_ref');
            $t->date('received_on');
            $t->decimal('charges_deducted', 14, 2)->default(0);
            $t->foreignId('marked_by')->constrained('users');
            $t->text('notes')->nullable();
            $t->boolean('reversed')->default(false);
            $t->timestamps();
        });
        Schema::create('storage_folders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paralegal_id')->constrained();
            $t->string('ref_no', 6);
            $t->date('folder_date');
            $t->string('storage_path');
            $t->unique(['paralegal_id', 'ref_no', 'folder_date']);
            $t->timestamps();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('matter_id')->nullable()->constrained();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->foreignId('storage_folder_id')->nullable()->constrained();
            $t->string('type');
            $t->string('name');
            $t->string('path');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->string('expense_type');
            $t->foreignId('vendor_id')->nullable()->constrained();
            $t->string('vendor_invoice_no')->nullable();
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->string('currency', 3);
            $t->decimal('amount', 14, 2);
            $t->decimal('gst_amount', 14, 2)->default(0);
            $t->string('ref_no', 6)->nullable();
            $t->string('category');
            $t->string('paid_by');
            $t->foreignId('paid_by_user_id')->constrained('users');
            $t->string('card_label')->nullable();
            $t->string('status')->default('Unpaid');
            $t->date('paid_on')->nullable();
            $t->string('paid_ref')->nullable();
            $t->string('reimbursement_status')->default('Pending');
            $t->date('reimbursed_on')->nullable();
            $t->string('reimbursed_ref')->nullable();
            $t->boolean('requires_15ca_cb')->default(false);
            $t->string('form_status')->nullable();
            $t->boolean('tds_applicable')->default(false);
            $t->decimal('tds_amount', 14, 2)->default(0);
            $t->string('bill_path')->nullable();
            $t->json('extraction_json')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('reminder_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_key')->unique();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('reminder_events');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('storage_folders');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_conversions');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('word_uploads');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('matters');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('paralegals');
        Schema::dropIfExists('clients');
    }
};
