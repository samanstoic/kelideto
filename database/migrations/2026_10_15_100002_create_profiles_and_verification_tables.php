<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Customer Profiles
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('emergency_contact_phone', 15)->nullable();
            $table->unsignedInteger('completed_bookings_count')->default(0);
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->timestamps();
        });

        // 2. Technician Profiles
        Schema::create('technician_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('license_number', 50)->unique()->nullable();
            $table->string('guild_registration_no', 50)->nullable(); // شماره ثبت اتحادیه
            $table->string('status', 25)->default('pending_verification')->index(); // pending_verification, verified, suspended, rejected
            $table->boolean('is_online')->default(false)->index();
            $table->boolean('is_available')->default(false)->index(); // true if not currently on a trip
            $table->decimal('current_lat', 10, 7)->nullable()->index();
            $table->decimal('current_lng', 10, 7)->nullable()->index();
            $table->timestamp('last_telemetry_at')->nullable()->index();
            $table->string('vehicle_type', 30)->nullable(); // motor, car, van
            $table->string('vehicle_plate', 30)->nullable();
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->unsignedInteger('total_jobs_completed')->default(0);
            $table->json('skills')->nullable(); // array of service item slugs
            $table->timestamps();
        });

        // 3. Technician Documents (National Card, Guild License, Background Check)
        Schema::create('technician_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->string('document_type', 40); // national_id, guild_license, criminal_record, vehicle_card
            $table->string('file_path');
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // 4. Verification Records (Police compliance & ownership check logs before door opening)
        Schema::create('verification_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customer_profiles')->nullOnDelete();
            $table->string('property_type', 30); // residential, commercial, vehicle, safe
            $table->string('ownership_doc_type', 40); // deed, lease_contract, utility_bill, vehicle_card
            $table->string('ownership_doc_photo_path');
            $table->string('customer_id_photo_path');
            $table->text('customer_digital_signature')->nullable();
            $table->boolean('police_notified')->default(false);
            $table->string('status', 20)->default('verified'); // verified, rejected, suspicious
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_records');
        Schema::dropIfExists('technician_documents');
        Schema::dropIfExists('technician_profiles');
        Schema::dropIfExists('customer_profiles');
    }
};
