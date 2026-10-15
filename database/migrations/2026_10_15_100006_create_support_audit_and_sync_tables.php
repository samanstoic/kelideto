<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Reviews and Reputation
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1 to 5 stars
            $table->unsignedTinyInteger('punctuality_rating')->nullable();
            $table->unsignedTinyInteger('expertise_rating')->nullable();
            $table->unsignedTinyInteger('fair_price_rating')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();
        });

        // 2. Support and Dispute Tickets
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('ticket_code')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('subject');
            $table->string('priority', 20)->default('medium')->index(); // low, medium, high, emergency
            $table->string('status', 25)->default('open')->index(); // open, in_progress, resolved, closed
            $table->string('category', 40); // payment_dispute, property_damage, late_arrival, misconduct
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->string('attachment_path')->nullable();
            $table->timestamps();
        });

        // 3. Notifications (SMS & In-app)
        Schema::create('notifications_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20); // sms, push, in_app
            $table->string('recipient', 50); // phone number or device token
            $table->string('template_key', 50);
            $table->text('body');
            $table->string('provider', 30)->default('mock'); // kavenegar, ghasedak, mock
            $table->string('status', 20)->default('sent')->index(); // sent, delivered, failed
            $table->string('provider_message_id', 100)->nullable();
            $table->timestamps();
        });

        // 4. Audit & Security Logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60)->index(); // user.login, booking.status_changed, technician.verified
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
        });

        // 5. Offline Sync Records
        Schema::create('offline_sync_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_record_uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity_type', 50); // booking, telemetry, review
            $table->string('action', 20); // create, update
            $table->json('payload');
            $table->string('sync_status', 20)->default('synced')->index(); // synced, conflict, rejected
            $table->timestamp('client_timestamp');
            $table->timestamp('server_synced_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_records');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications_log');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('reviews');
    }
};
