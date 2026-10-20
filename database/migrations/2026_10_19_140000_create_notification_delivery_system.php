<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_log', function (Blueprint $table) {
            $table->string('event_key', 80)->nullable()->index();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->json('payload')->nullable();
            $table->string('provider_status', 20)->default('queued')->index();
            $table->string('delivery_status', 20)->default('unknown')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamp('read_at')->nullable();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 80);
            $table->string('channel', 20);
            $table->string('title_fa', 160)->nullable();
            $table->text('body_fa');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['event_key', 'channel']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_key', 80);
            $table->string('channel', 20);
            $table->boolean('enabled')->default(true);
            $table->unsignedTinyInteger('hourly_limit')->default(10);
            $table->timestamps();
            $table->unique(['user_id', 'event_key', 'channel']);
        });

        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 30)->default('aes128gcm');
            $table->timestamp('consented_at');
            $table->timestamps();
            $table->index(['user_id', 'consented_at']);
        });

        Schema::create('booking_schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_key', 80);
            $table->timestamp('previous_scheduled_at')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamps();
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_schedule_changes');
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
        Schema::table('notifications_log', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['event_key']);
            $table->dropIndex(['provider_status']);
            $table->dropIndex(['delivery_status']);
            $table->dropIndex(['next_attempt_at']);
            $table->dropColumn([
                'event_key',
                'idempotency_key',
                'payload',
                'provider_status',
                'delivery_status',
                'attempts',
                'next_attempt_at',
                'last_error',
                'read_at',
            ]);
        });
    }
};
