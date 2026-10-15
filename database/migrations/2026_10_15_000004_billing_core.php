<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------- Wallet ledger ----------
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Signed amount: credits are positive, debits are negative.
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('type', 30); // topup|voucher|manual_credit|manual_debit|subscription_charge|invoice_payment|refund
            $table->nullableMorphs('reference'); // payment, voucher, invoice, subscription…
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---------- Invoices (postpaid) ----------
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique(); // INV-202610-0001
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['draft', 'unpaid', 'paid', 'cancelled'])->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        // ---------- Payments (Monetbil and future gateways) ----------
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 30)->default('monetbil');
            $table->string('payment_ref')->unique();
            $table->string('item_ref')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('XAF');
            $table->string('phone', 30)->nullable();
            $table->string('operator', 50)->nullable();
            $table->string('operator_transaction_id', 60)->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'cancelled'])->default('pending');
            $table->json('raw_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // ---------- Vouchers ----------
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('value', 12, 2);
            $table->enum('status', ['active', 'redeemed', 'void'])->default('active');
            $table->string('batch_name')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        // ---------- Tickets ----------
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->enum('status', ['open', 'pending', 'closed'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->enum('author_type', ['staff', 'client']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // ---------- Settings (payment gateway config etc.) ----------
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // ---------- Client portal credentials ----------
        Schema::table('clients', function (Blueprint $table) {
            $table->string('portal_password')->nullable();
            $table->timestamp('portal_password_set_at')->nullable();
        });

        // ---------- Plan billing mode ----------
        Schema::table('plans', function (Blueprint $table) {
            $table->enum('billing_mode', ['prepaid', 'postpaid'])->default('prepaid');
        });

        // ---------- Subscription auto-renew ----------
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('auto_renew');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('billing_mode');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['portal_password', 'portal_password_set_at']);
        });

        Schema::dropIfExists('settings');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('wallet_transactions');
    }
};
