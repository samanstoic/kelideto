<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->char('currency', 3)->default('IRR');
            $table->string('provider_authority', 100)->nullable()->unique();
            $table->text('redirect_url')->nullable();
            $table->timestamp('verified_at')->nullable();
        });
        DB::table('payments')->update(['amount_minor' => DB::raw('ROUND(amount, 0)')]);

        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('event_key', 128)->nullable()->unique();
            $table->char('payload_sha256', 64)->nullable();
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->bigInteger('balance_minor')->default(0);
        });
        DB::table('financial_accounts')->update(['balance_minor' => DB::raw('ROUND(balance, 0)')]);

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_group_id')->unique();
            $table->string('idempotency_key', 160)->unique();
            $table->char('payload_sha256', 64);
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('reversal_of_transaction_id')->nullable()
                ->constrained('ledger_transactions')->nullOnDelete();
            $table->char('currency', 3)->default('IRR');
            $table->unsignedBigInteger('amount_minor');
            $table->string('description');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->foreignId('ledger_transaction_id')->nullable()
                ->constrained('ledger_transactions')->nullOnDelete();
        });
        DB::table('ledger_entries')->update(['amount_minor' => DB::raw('ROUND(amount, 0)')]);

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->unique()->constrained('financial_accounts')->restrictOnDelete();
            $table->char('currency', 3)->default('IRR');
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->string('type', 30);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('IRR');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('description');
            $table->timestamps();
            $table->index(['wallet_id', 'created_at']);
        });

        Schema::table('technician_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->char('currency', 3)->default('IRR');
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->foreignId('ledger_transaction_id')->nullable()
                ->constrained('ledger_transactions')->nullOnDelete();
            $table->foreignId('payout_ledger_transaction_id')->nullable()
                ->constrained('ledger_transactions')->nullOnDelete();
            $table->foreignId('settled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
        });
        DB::table('technician_settlements')->update(['amount_minor' => DB::raw('ROUND(amount, 0)')]);

        Schema::create('technician_settlement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('technician_settlements')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payout_reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['settlement_id', 'created_at']);
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('refund_uuid')->unique();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('IRR');
            $table->string('status', 20)->default('posted');
            $table->text('reason');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at');
            $table->timestamps();
        });

        Schema::create('gateway_health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_name', 30);
            $table->string('check_type', 30);
            $table->string('provider_reference_hash', 64);
            $table->boolean('passed');
            $table->string('result_code', 40)->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['gateway_name', 'passed', 'checked_at']);
        });

        Schema::create('finance_reconciliation_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('report_uuid')->unique();
            $table->string('gateway_name', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('payments_checked')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->json('mismatches');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reconciliation_reports');
        Schema::dropIfExists('gateway_health_checks');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('technician_settlement_events');

        Schema::table('technician_settlements', function (Blueprint $table) {
            $table->dropForeign(['ledger_transaction_id']);
            $table->dropForeign(['payout_ledger_transaction_id']);
            $table->dropForeign(['settled_by_user_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['amount_minor', 'currency', 'idempotency_key', 'ledger_transaction_id', 'payout_ledger_transaction_id', 'settled_by_user_id', 'rejection_reason']);
        });

        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['ledger_transaction_id']);
            $table->dropColumn(['amount_minor', 'ledger_transaction_id']);
        });

        Schema::dropIfExists('ledger_transactions');

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropColumn('balance_minor');
        });

        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropUnique(['event_key']);
            $table->dropColumn(['event_key', 'payload_sha256']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['provider_authority']);
            $table->dropColumn(['amount_minor', 'currency', 'provider_authority', 'redirect_url', 'verified_at']);
        });
    }
};
