<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServicePricingPhaseSixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('auth.guards.sanctum')) {
            $this->app->register(\Laravel\Sanctum\SanctumServiceProvider::class);
        }
        $this->travelTo(CarbonImmutable::now()->startOfDay()->setTime(12, 0));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_estimate_uses_server_tariff_location_and_scheduled_time_not_client_amounts(): void
    {
        [$categoryId, $serviceId, $areaId] = $this->createService();
        $this->createTariff($serviceId, $areaId, [
            'base_fare' => 100000,
            'dispatch_fee' => 20000,
            'per_km_rate' => 10000,
            'per_minute_rate' => 1000,
            'minimum_total' => 0,
            'minimum_trip_km' => 3,
            'night_multiplier' => 2,
        ]);
        $customer = $this->createUserWithRole('customer');
        Sanctum::actingAs($customer);

        $day = $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'scheduled_at' => now()->addDay()->setTime(12, 0)->toIso8601String(),
            'base_fare' => 1,
            'amount' => 1,
            'total_price' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.approximate', true)
            ->assertJsonPath('data.currency', 'IRR')
            ->assertJsonPath('data.components.base_fare', 100000)
            ->assertJsonPath('data.components.dispatch_fee', 20000)
            ->assertJsonPath('data.components.distance_fare', 30000)
            ->assertJsonPath('data.components.time_fare', 30000)
            ->assertJsonPath('data.estimated_subtotal', 180000);

        $night = $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'scheduled_at' => now()->addDay()->setTime(23, 0)->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.components.night_multiplier', 2)
            ->assertJsonPath('data.estimated_subtotal', 360000);

        $this->assertDatabaseHas('price_estimates', [
            'estimate_uuid' => $day->json('data.estimate_id'),
            'service_id' => $serviceId,
            'service_area_id' => $areaId,
            'tariff_version' => 1,
        ]);
    }

    public function test_uncovered_locations_inactive_services_and_unpriced_areas_are_rejected(): void
    {
        [, $serviceId] = $this->createService();
        $customer = $this->createUserWithRole('customer');
        Sanctum::actingAs($customer);

        $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 36.5,
            'longitude' => 52.2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['location']);

        DB::table('services')->where('id', $serviceId)->update(['is_active' => false]);
        $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 35.7,
            'longitude' => 51.4,
        ])->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    public function test_booking_persists_the_quoted_tariff_snapshot_and_requires_authority_attestation(): void
    {
        [, $serviceId, $areaId] = $this->createService();
        $tariffId = $this->createTariff($serviceId, $areaId, [
            'effective_until' => now()->addDays(2),
        ]);
        $customer = $this->createUserWithRole('customer');
        Sanctum::actingAs($customer);
        $estimateId = $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data.estimate_id');

        $this->createTariff($serviceId, $areaId, [
            'base_fare' => 999999,
            'effective_from' => now()->addDays(2),
        ]);

        $bookingPayload = [
            'estimate_id' => $estimateId,
            'street_address' => 'آدرس آزمایشی',
            'ownership_attested' => true,
            'property_type' => 'residential',
            'estimated_price' => 1,
            'final_price' => 1,
            'service_tariff_id' => 99999,
        ];
        $this->postJson('/api/bookings', array_diff_key($bookingPayload, ['ownership_attested' => true, 'property_type' => 'residential']))
            ->assertUnprocessable()
            ->assertJson(['error' => 'CUSTOMER_AUTHORIZATION_REQUIRED']);

        $created = $this->postJson('/api/bookings', $bookingPayload)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.tariff_version', 1)
            ->assertJsonPath('data.pricing_snapshot.service_tariff_id', $tariffId)
            ->assertJsonPath('data.authorization_status', 'customer_attested')
            ->assertJsonPath('already_created', false);
        $bookingId = $created->json('data.booking_id');

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'price_estimate_id' => DB::table('price_estimates')->where('estimate_uuid', $estimateId)->value('id'),
            'estimated_price' => 180000,
            'service_tariff_id' => $tariffId,
            'tariff_version' => 1,
        ]);
        $this->assertDatabaseHas('booking_authorizations', [
            'booking_id' => $bookingId,
            'customer_id' => DB::table('customer_profiles')->where('user_id', $customer->id)->value('id'),
            'property_type' => 'residential',
            'status' => 'customer_attested',
        ]);
        $this->postJson('/api/bookings', $bookingPayload)->assertOk()->assertJsonPath('already_created', true);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_admin_creates_nonoverlapping_immutable_tariff_versions_and_manages_catalog(): void
    {
        [, $serviceId, $areaId] = $this->createService();
        $admin = $this->createUserWithRole('super_admin');
        Sanctum::actingAs($admin);

        $payload = $this->tariffPayload($serviceId, $areaId, [
            'effective_from' => now()->addYear()->toIso8601String(),
        ]);
        $firstVersion = $this->postJson('/api/admin/pricing/tariffs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.version', 1);
        $this->postJson('/api/admin/pricing/tariffs', $payload)->assertUnprocessable();

        $nextVersion = $this->tariffPayload($serviceId, $areaId, [
            'effective_from' => now()->addYears(2)->toIso8601String(),
        ]);
        $this->postJson('/api/admin/pricing/tariffs', $nextVersion)
            ->assertCreated()
            ->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('service_tariffs', [
            'id' => $firstVersion->json('data.id'),
            'effective_until' => now()->addYears(2)->format('Y-m-d H:i:s'),
        ]);

        $customer = $this->createUserWithRole('customer');
        Sanctum::actingAs($customer);
        $this->withHeader('X-User-Id', $admin->id)
            ->postJson('/api/admin/catalog/categories', [
                'name_fa' => 'ساختمان',
                'slug' => 'building-services',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN_PRICING_MANAGEMENT');
    }

    public function test_catalog_shows_only_active_services_and_areas_enforce_dispatch_range(): void
    {
        [$categoryId, $serviceId, $areaId] = $this->createService();
        $admin = $this->createUserWithRole('super_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/services')->assertOk()->assertJsonCount(1, 'data');
        $this->assertTrue(Cache::has('public:service-catalog:v1'));

        $this->patchJson("/api/admin/service-areas/{$areaId}", [
            'minimum_trip_km' => 30,
        ])->assertUnprocessable()->assertJsonPath('error', 'INVALID_DISPATCH_RANGE');
        $this->patchJson("/api/admin/catalog/services/{$serviceId}", [
            'is_active' => false,
        ])->assertOk();
        $this->assertFalse(Cache::has('public:service-catalog:v1'));

        Sanctum::actingAs($this->createUserWithRole('customer'));
        $this->getJson('/api/services')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([], $this->getJson('/api/services')->json('data.0.services'));

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/catalog/categories/{$categoryId}", [
            'is_active' => false,
        ])->assertOk();
        $this->assertFalse(Cache::has('public:service-catalog:v1'));

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/console/resources/categories/{$categoryId}", [
            'is_active' => true,
            'reason' => 'بازگردانی دسته برای آزمون cache',
        ])->assertOk();
        $this->assertFalse(Cache::has('public:service-catalog:v1'));
        $this->patchJson("/api/admin/console/resources/services/{$serviceId}", [
            'is_active' => true,
            'reason' => 'بازگردانی خدمت برای آزمون cache',
        ])->assertOk();
        $this->getJson('/api/services')->assertOk()->assertJsonCount(1, 'data');
        $this->assertTrue(Cache::has('public:service-catalog:v1'));
        $this->patchJson("/api/admin/console/resources/services/{$serviceId}", [
            'is_active' => false,
            'reason' => 'غیرفعال‌سازی خدمت برای آزمون cache',
        ])->assertOk();
        $this->assertFalse(Cache::has('public:service-catalog:v1'));
        $this->patchJson("/api/admin/console/resources/categories/{$categoryId}", [
            'is_active' => false,
            'reason' => 'غیرفعال‌سازی دسته برای آزمون cache',
        ])->assertOk();
        $this->assertFalse(Cache::has('public:service-catalog:v1'));

        Sanctum::actingAs($this->createUserWithRole('customer'));
        $this->getJson('/api/services')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_seeded_catalog_contains_requested_service_categories_without_fake_services_or_prices(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $expectedSlugs = [
            'building',
            'automotive',
            'cylinder-replacement',
            'lock-repair',
            'lock-opening',
            'other-authorized-services',
        ];

        $this->assertSame($expectedSlugs, DB::table('service_categories')->orderBy('display_order')->pluck('slug')->all());
        $this->assertSame(0, DB::table('services')->count());

        $this->seed(ServiceCatalogSeeder::class);
        $this->assertSame(6, DB::table('service_categories')->count());
    }

    public function test_additional_cost_is_server_priced_pending_customer_approval_and_one_time(): void
    {
        [, $serviceId, $areaId] = $this->createService('opening-service');
        $this->createTariff($serviceId, $areaId);
        [, $additionalServiceId] = $this->createService('repair-service');
        $this->createTariff($additionalServiceId, $areaId, [
            'base_fare' => 50000,
            'dispatch_fee' => 10000,
            'per_km_rate' => 0,
            'per_minute_rate' => 0,
            'minimum_total' => 0,
            'minimum_trip_km' => 0,
        ]);
        $customer = $this->createUserWithRole('customer');
        $customerProfileId = DB::table('customer_profiles')->where('user_id', $customer->id)->value('id');
        $technician = $this->createUserWithRole('technician');
        $technicianProfileId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $technician->id,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherTechnician = $this->createUserWithRole('technician');
        DB::table('technician_profiles')->insert([
            'user_id' => $otherTechnician->id,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bookingId = DB::table('bookings')->insertGetId([
            'booking_code' => (string) Str::uuid(),
            'customer_id' => $customerProfileId,
            'technician_id' => $technicianProfileId,
            'service_id' => $serviceId,
            'status' => 'arrived',
            'location_access_expires_at' => now()->addHours(12),
            'latitude' => 35.7,
            'longitude' => 51.4,
            'street_address' => 'آدرس آزمایشی',
            'estimated_price' => 180000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assignments')->insert([
            'booking_id' => $bookingId,
            'technician_id' => $technicianProfileId,
            'status' => 'active',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('booking_authorizations')->insert([
            'booking_id' => $bookingId,
            'customer_id' => $customerProfileId,
            'property_type' => 'residential',
            'attestation_version' => 'keypro-entry-authority-v1',
            'status' => 'customer_attested',
            'attested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($otherTechnician);
        $this->postJson("/api/bookings/{$bookingId}/customer-authority/verify", [
            'verified' => true,
        ])->assertForbidden();

        Sanctum::actingAs($technician);
        DB::table('bookings')->where('id', $bookingId)->update(['status' => 'in_progress']);
        $this->postJson("/api/bookings/{$bookingId}/additional-costs", [
            'additional_service_id' => $additionalServiceId,
            'reason' => 'قبل از بررسی اختیار',
        ])->assertUnprocessable()->assertJsonPath('error', 'TECHNICIAN_AUTHORITY_CHECK_REQUIRED');
        DB::table('bookings')->where('id', $bookingId)->update(['status' => 'arrived']);

        $this->postJson("/api/bookings/{$bookingId}/customer-authority/verify", [
            'verified' => true,
        ])->assertOk()->assertJsonPath('data.status', 'technician_verified');
        $this->assertDatabaseHas('booking_authorizations', [
            'booking_id' => $bookingId,
            'status' => 'technician_verified',
            'reviewed_by_user_id' => $technician->id,
        ]);
        DB::table('bookings')->where('id', $bookingId)->update(['status' => 'in_progress']);

        $cost = $this->postJson("/api/bookings/{$bookingId}/additional-costs", [
            'additional_service_id' => $additionalServiceId,
            'reason' => 'قطعهٔ اصلی نیاز به تعمیر تکمیلی دارد.',
            'quantity' => 2,
            'additional_amount' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.additional_amount', 120000);
        $costId = $cost->json('data.id');
        $this->assertDatabaseHas('scope_change_requests', [
            'id' => $costId,
            'additional_amount' => 120000,
            'tariff_version' => 1,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($otherTechnician);
        $this->postJson("/api/bookings/{$bookingId}/additional-costs", [
            'additional_service_id' => $additionalServiceId,
            'reason' => 'درخواست غیرمجاز متخصص دیگر',
        ])->assertForbidden();

        $otherCustomer = $this->createUserWithRole('customer');
        Sanctum::actingAs($otherCustomer);
        $this->postJson("/api/bookings/{$bookingId}/additional-costs/{$costId}/respond", [
            'approved' => true,
        ])->assertForbidden();

        Sanctum::actingAs($customer);
        $this->getJson("/api/bookings/{$bookingId}/additional-costs")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.reason', 'قطعهٔ اصلی نیاز به تعمیر تکمیلی دارد.');
        $this->postJson("/api/bookings/{$bookingId}/additional-costs/{$costId}/respond", [
            'approved' => true,
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/bookings/{$bookingId}/additional-costs/{$costId}/respond", [
            'approved' => true,
        ])->assertStatus(409);
        $this->assertDatabaseHas('customer_approvals', [
            'scope_change_id' => $costId,
            'customer_id' => $customerProfileId,
            'approved' => true,
        ]);
    }

    private function createService(string $slug = 'door-opening'): array
    {
        $now = now();
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمات ساختمان',
            'slug' => 'building-' . Str::random(6),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'خدمت آزمایشی',
            'slug' => $slug . '-' . Str::random(6),
            'base_price' => 100000,
            'estimated_duration_minutes' => 30,
            'requires_ownership_proof' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $areaId = DB::table('service_areas')->insertGetId([
            'city_name' => 'تهران',
            'zone_name' => 'منطقه آزمایشی',
            'slug' => 'tehran-' . Str::random(6),
            'center_lat' => 35.7,
            'center_lng' => 51.4,
            'radius_km' => 25,
            'minimum_trip_km' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$categoryId, $serviceId, $areaId];
    }

    private function createTariff(int $serviceId, int $areaId, array $overrides = []): int
    {
        $payload = $this->tariffPayload($serviceId, $areaId, $overrides);
        $creatorId = DB::table('users')->value('id') ?? $this->createUserWithRole('super_admin')->id;
        $version = (int) DB::table('service_tariffs')
            ->where('service_id', $serviceId)
            ->where('service_area_id', $areaId)
            ->max('version') + 1;
        return DB::table('service_tariffs')->insertGetId($payload + [
            'version' => $version,
            'created_by_user_id' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tariffPayload(int $serviceId, int $areaId, array $overrides = []): array
    {
        return array_replace([
            'service_id' => $serviceId,
            'service_area_id' => $areaId,
            'base_fare' => 100000,
            'dispatch_fee' => 20000,
            'per_km_rate' => 10000,
            'per_minute_rate' => 1000,
            'minimum_total' => 0,
            'minimum_trip_km' => 3,
            'estimate_margin_percent' => 10,
            'night_multiplier' => 1,
            'night_start' => '22:00',
            'night_end' => '06:00',
            'effective_from' => now()->subDay()->toIso8601String(),
            'effective_until' => null,
        ], $overrides);
    }

    private function createUserWithRole(string $roleName): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'کاربر آزمایشی',
            'phone_number' => '09' . random_int(100000000, 999999999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('name', $roleName)->value('id');
        DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        if ($roleName === 'customer') {
            DB::table('customer_profiles')->insert([
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return User::findOrFail($userId);
    }
}
