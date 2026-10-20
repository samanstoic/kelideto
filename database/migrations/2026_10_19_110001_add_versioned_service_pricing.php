<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table) {
            $table->decimal('minimum_trip_km', 6, 2)->default(0)->after('radius_km');
        });

        Schema::create('service_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_area_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('base_fare', 12, 2);
            $table->decimal('dispatch_fee', 12, 2)->default(0);
            $table->decimal('per_km_rate', 12, 2)->default(0);
            $table->decimal('per_minute_rate', 12, 2)->default(0);
            $table->decimal('minimum_total', 12, 2)->default(0);
            $table->decimal('minimum_trip_km', 6, 2)->default(0);
            $table->decimal('estimate_margin_percent', 5, 2)->default(10);
            $table->decimal('night_multiplier', 4, 2)->default(1);
            $table->time('night_start')->default('22:00:00');
            $table->time('night_end')->default('06:00:00');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'service_area_id', 'version']);
            $table->index(['service_id', 'service_area_id', 'effective_from', 'effective_until'], 'service_tariffs_effectivity_index');
        });

        Schema::table('price_estimates', function (Blueprint $table) {
            $table->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_tariff_id')->nullable()->constrained('service_tariffs')->restrictOnDelete();
            $table->unsignedInteger('tariff_version')->nullable();
            $table->decimal('dispatch_fee', 12, 2)->default(0);
            $table->decimal('time_fare', 12, 2)->default(0);
            $table->decimal('minimum_trip_km', 6, 2)->default(0);
            $table->json('pricing_snapshot')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('price_estimate_id')->nullable()->unique()->constrained('price_estimates')->restrictOnDelete();
            $table->foreignId('service_tariff_id')->nullable()->constrained('service_tariffs')->restrictOnDelete();
            $table->unsignedInteger('tariff_version')->nullable();
            $table->json('pricing_snapshot')->nullable();
        });

        Schema::create('booking_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->string('property_type', 30);
            $table->string('attestation_version', 50);
            $table->string('status', 30)->default('customer_attested');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('customer_ip', 45)->nullable();
            $table->timestamp('attested_at');
            $table->timestamps();
        });

        Schema::table('scope_change_requests', function (Blueprint $table) {
            $table->foreignId('additional_service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->foreignId('service_tariff_id')->nullable()->constrained('service_tariffs')->restrictOnDelete();
            $table->unsignedInteger('tariff_version')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->json('pricing_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scope_change_requests', function (Blueprint $table) {
            $table->dropForeign(['additional_service_id']);
            $table->dropForeign(['service_tariff_id']);
            $table->dropColumn(['additional_service_id', 'service_tariff_id', 'tariff_version', 'quantity', 'pricing_snapshot']);
        });

        Schema::dropIfExists('booking_authorizations');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['price_estimate_id']);
            $table->dropForeign(['service_tariff_id']);
            $table->dropUnique(['price_estimate_id']);
            $table->dropColumn(['price_estimate_id', 'service_tariff_id', 'tariff_version', 'pricing_snapshot']);
        });

        Schema::table('price_estimates', function (Blueprint $table) {
            $table->dropForeign(['service_area_id']);
            $table->dropForeign(['service_tariff_id']);
            $table->dropColumn([
                'service_area_id',
                'service_tariff_id',
                'tariff_version',
                'dispatch_fee',
                'time_fare',
                'minimum_trip_km',
                'pricing_snapshot',
            ]);
        });

        Schema::dropIfExists('service_tariffs');

        Schema::table('service_areas', function (Blueprint $table) {
            $table->dropColumn('minimum_trip_km');
        });
    }
};
