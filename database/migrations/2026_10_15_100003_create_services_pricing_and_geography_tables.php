<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Service Categories
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_fa');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // 2. Services / Service Items
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->string('title_fa');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('base_price', 12, 2); // In Iranian Tomans / Rials
            $table->decimal('estimated_duration_minutes', 5, 2)->default(30.00);
            $table->string('difficulty_level', 20)->default('medium'); // easy, medium, hard, expert
            $table->boolean('requires_ownership_proof')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // 3. Service Areas (Cities, Metropolitan zones, Coverage bounds)
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id();
            $table->string('city_name');
            $table->string('zone_name');
            $table->string('slug')->unique();
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->decimal('radius_km', 6, 2)->default(25.00);
            $table->json('polygon_geojson')->nullable(); // MultiPolygon / Polygon coordinates
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // 4. Dynamic Pricing Rules
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('rule_name');
            $table->decimal('per_km_rate', 10, 2)->default(5000.00); // Price per km travel
            $table->decimal('night_surge_multiplier', 4, 2)->default(1.50); // 22:00 to 06:00
            $table->decimal('emergency_multiplier', 4, 2)->default(1.30);
            $table->decimal('holiday_multiplier', 4, 2)->default(1.20);
            $table->decimal('min_trip_fare', 12, 2)->default(50000.00);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // 5. Addresses and Geographic Locations
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('title', 60)->default('آدرس من');
            $table->string('city', 50)->default('تهران');
            $table->text('street_address');
            $table->string('building_number', 20)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 10, 7)->index();
            $table->decimal('longitude', 10, 7)->index();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('service_areas');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
