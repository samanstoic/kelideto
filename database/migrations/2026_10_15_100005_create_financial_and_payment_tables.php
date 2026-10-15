<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Financial Accounts (Double-Entry Ledger)
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 50)->unique();
            $table->string('account_name');
            $table->string('type', 30)->index(); // asset, liability, equity, revenue, expense
            $table->string('owner_type')->nullable(); // user, platform, technician
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->decimal('balance', 16, 2)->default(0);
            $table->string('currency', 10)->default('IRR');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        // 2. Payments (Shaparak Gateway transactions)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('payment_uuid')->unique();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('gateway_name', 30)->default('mock'); // zarinpal, saman, mock
            $table->string('transaction_reference', 100)->nullable()->unique();
            $table->string('tracking_code', 100)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 25)->default('pending')->index(); // pending, paid, failed, refunded
            $table->json('gateway_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // 3. Payment Events (Webhook & State auditing)
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('event_type', 40); // callback_received, verified, rejected, refund_issued
            $table->string('source_ip', 45)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        // 4. Ledger Entries (Strict Double-Entry accounting transactions)
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_group_id')->index(); // groups matched debit & credit
            $table->foreignId('account_id')->constrained('financial_accounts')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('entry_type', 10); // debit, credit
            $table->decimal('amount', 14, 2);
            $table->string('description');
            $table->timestamp('posted_at')->index();
            $table->timestamps();
        });

        // 5. Technician Settlements (Paya / Satna bank transfers to Sheba)
        Schema::create('technician_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('settlement_uuid')->unique();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('sheba_number', 34); // IRxx xxxx xxxx xxxx xxxx xxxx xx
            $table->string('bank_name', 50)->nullable();
            $table->string('account_holder_name');
            $table->string('status', 25)->default('pending')->index(); // pending, approved, processing, settled, rejected
            $table->string('paya_reference', 100)->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_settlements');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('financial_accounts');
    }
};
