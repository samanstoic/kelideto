<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Price Estimates (Prior to booking submission)
        Schema::create('price_estimates', function (Blueprint $table) {
            $table->id();
            $table->uuid('estimate_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->decimal('estimated_distance_km', 6, 2)->default(0);
            $table->decimal('base_fare', 12, 2);
            $table->decimal('distance_fare', 12, 2);
            $table->decimal('surge_amount', 12, 2)->default(0);
            $table->decimal('min_total_price', 12, 2);
            $table->decimal('max_total_price', 12, 2);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('price_estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('price_estimates')->cascadeOnDelete();
            $table->string('item_name');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });

        // 2. Bookings (Core Order Lifecycle State Machine)
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('booking_code')->unique(); // e.g. KP-202610-8849
            $table->foreignId('customer_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('technician_profiles')->nullOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            
            // Status Machine: draft, broadcasting, assigned, en_route, arrived, in_progress, payment_pending, completed, cancelled, disputed
            $table->string('status', 30)->default('draft')->index();
            
            // Location
            $table->decimal('latitude', 10, 7)->index();
            $table->decimal('longitude', 10, 7)->index();
            $table->text('street_address');
            $table->text('problem_description')->nullable();
            $table->string('lock_photo_path')->nullable();
            
            // Financials
            $table->decimal('estimated_price', 12, 2);
            $table->decimal('final_price', 12, 2)->nullable();
            $table->decimal('platform_commission_rate', 5, 2)->default(15.00); // 15%
            $table->decimal('platform_commission_amount', 12, 2)->default(0);
            $table->decimal('technician_earnings', 12, 2)->default(0);
            
            // Timing
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('cancelled_by', 30)->nullable(); // customer, technician, dispatcher, system
            
            $table->timestamps();
        });

        // 3. Booking Status History (Audit trail of transitions)
        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->decimal('technician_lat', 10, 7)->nullable();
            $table->decimal('technician_lng', 10, 7)->nullable();
            $table->timestamps();
        });

        // 4. Dispatch Offers & Assignments (Atomic state & timeout protection)
        Schema::create('dispatch_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->unsignedInteger('round_number')->default(1);
            $table->decimal('distance_km', 6, 2);
            $table->decimal('offered_earnings', 12, 2);
            $table->string('status', 20)->default('pending')->index(); // pending, accepted, rejected, expired
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['booking_id', 'technician_id', 'round_number']);
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('dispatch_offers')->nullOnDelete();
            $table->string('status', 20)->default('active')->index(); // active, fulfilled, cancelled
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        // 5. Scope Change Requests & Customer Approvals
        Schema::create('scope_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->string('reason'); // e.g. Cylinder broken, requires high-security replacement
            $table->decimal('additional_amount', 12, 2);
            $table->json('part_details')->nullable(); // parts replaced, brand, warranty
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected, cancelled
            $table->timestamps();
        });

        Schema::create('customer_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_change_id')->unique()->constrained('scope_change_requests')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->boolean('approved')->default(false);
            $table->string('approval_method', 30)->default('app_click'); // app_click, sms_code, verbal_operator
            $table->string('customer_ip', 45)->nullable();
            $table->timestamp('responded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_approvals');
        Schema::dropIfExists('scope_change_requests');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('dispatch_offers');
        Schema::dropIfExists('booking_status_history');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('price_estimate_items');
        Schema::dropIfExists('price_estimates');
    }
};
