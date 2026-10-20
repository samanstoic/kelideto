<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('urgency', 20)->default('urgent');
            $table->uuid('request_idempotency_key')->nullable();
            $table->char('request_payload_hash', 64)->nullable();
            $table->unique(
                ['customer_id', 'request_idempotency_key'],
                'bookings_customer_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_customer_idempotency_unique');
            $table->dropColumn(['urgency', 'request_idempotency_key', 'request_payload_hash']);
        });
    }
};
