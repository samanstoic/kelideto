<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_documents', function (Blueprint $table) {
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('requires_completion_evidence')->default(false);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('location_access_expires_at')->nullable()->index();
        });

        Schema::create('technician_services', function (Blueprint $table) {
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['technician_id', 'service_id']);
        });

        Schema::create('technician_service_areas', function (Blueprint $table) {
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->foreignId('service_area_id')->constrained('service_areas')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['technician_id', 'service_area_id']);
        });

        Schema::create('technician_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedTinyInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['technician_id', 'day_of_week', 'starts_at', 'ends_at'], 'technician_hours_slot_unique');
            $table->index(['technician_id', 'day_of_week', 'is_active'], 'technician_hours_current_index');
        });

        Schema::create('booking_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('technician_profiles')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_evidence');
        Schema::dropIfExists('technician_working_hours');
        Schema::dropIfExists('technician_service_areas');
        Schema::dropIfExists('technician_services');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_location_access_expires_at_index');
            $table->dropColumn('location_access_expires_at');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('requires_completion_evidence');
        });

        Schema::table('technician_documents', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'mime_type', 'file_size_bytes', 'sha256']);
        });
    }
};
