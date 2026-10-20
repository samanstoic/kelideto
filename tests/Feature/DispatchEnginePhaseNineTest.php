<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TechnicianOperationsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchEnginePhaseNineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TechnicianOperationsSeeder::class);
    }

    public function test_dispatch_offers_one_nearest_eligible_technician_and_advances_after_rejection(): void
    {
        [$serviceId, $areaId] = $this->createServiceAndArea();
        $booking = $this->createBooking($serviceId, $areaId, 35.7, 51.4);
        $farther = $this->createTechnician($serviceId, $areaId, 35.75, 51.4);
        $nearest = $this->createTechnician($serviceId, $areaId, 35.701, 51.4);
        $dispatcher = $this->createUser('dispatcher');

        Sanctum::actingAs($dispatcher);
        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch", [
            'response_window_seconds' => 60,
        ])->assertCreated()
            ->assertJsonPath('data.technician_id', $nearest['profile_id'])
            ->assertJsonPath('data.travel_time_status', 'unavailable')
            ->assertJsonPath('data.estimated_travel_time_minutes', null);
        $this->assertDatabaseCount('dispatch_offers', 1);
        $this->assertDatabaseHas('dispatch_offers', [
            'booking_id' => $booking['id'],
            'technician_id' => $nearest['profile_id'],
            'status' => 'pending',
        ]);

        Sanctum::actingAs($nearest['user']);
        $offerId = DB::table('dispatch_offers')->where('booking_id', $booking['id'])->value('id');
        $this->postJson("/api/technician/offers/{$offerId}/respond", [
            'decision' => 'rejected',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.next_offer.technician_id', $farther['profile_id']);
        $this->assertDatabaseCount('dispatch_offers', 2);
        $this->assertDatabaseHas('dispatch_events', [
            'booking_id' => $booking['id'],
            'event_type' => 'offer.rejected',
            'technician_id' => $nearest['profile_id'],
        ]);
        $this->assertDatabaseHas('dispatch_events', [
            'booking_id' => $booking['id'],
            'event_type' => 'offer.issued',
            'technician_id' => $farther['profile_id'],
        ]);
    }

    public function test_no_technician_in_the_booking_service_region_returns_an_explicit_empty_dispatch(): void
    {
        [$serviceId, $areaId] = $this->createServiceAndArea();
        $booking = $this->createBooking($serviceId, $areaId, 36.2, 51.4);
        $this->createTechnician($serviceId, $areaId, 35.7, 51.4);
        Sanctum::actingAs($this->createUser('dispatcher'));

        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch")
            ->assertOk()
            ->assertJsonPath('data.status', 'no_eligible_technicians')
            ->assertJsonPath('data.estimated_travel_time_minutes', null);

        $this->assertDatabaseCount('dispatch_offers', 0);
        $this->assertDatabaseHas('dispatch_events', [
            'booking_id' => $booking['id'],
            'event_type' => 'dispatch.no_eligible_technicians',
        ]);
    }

    public function test_operator_can_manually_assign_only_an_eligible_technician_with_an_audited_reason(): void
    {
        [$serviceId, $areaId] = $this->createServiceAndArea();
        $booking = $this->createBooking($serviceId, $areaId);
        $technician = $this->createTechnician($serviceId, $areaId);
        $operator = $this->createUser('operator');
        Sanctum::actingAs($operator);

        $this->postJson("/api/admin/bookings/{$booking['id']}/assign", [
            'technician_id' => $technician['profile_id'],
            'reason' => 'اعزام ویژه با تأیید سرپرست شیفت.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'assigned');

        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseHas('dispatch_events', [
            'booking_id' => $booking['id'],
            'technician_id' => $technician['profile_id'],
            'actor_user_id' => $operator->id,
            'event_type' => 'assignment.manual',
            'reason' => 'اعزام ویژه با تأیید سرپرست شیفت.',
        ]);
        $this->assertDatabaseHas('booking_status_history', [
            'booking_id' => $booking['id'],
            'to_status' => 'assigned',
            'changed_by_user_id' => $operator->id,
            'comment' => 'تخصیص دستی: اعزام ویژه با تأیید سرپرست شیفت.',
        ]);
        $this->postJson("/api/admin/bookings/{$booking['id']}/assign", [
            'technician_id' => $technician['profile_id'],
            'reason' => 'تلاش تکراری برای همان سفارش.',
        ])->assertConflict()
            ->assertJsonPath('error', 'BOOKING_NOT_DISPATCHABLE');
        $this->getJson("/api/admin/bookings/{$booking['id']}/dispatch-events")
            ->assertOk()
            ->assertJsonFragment([
                'event_type' => 'assignment.manual',
                'reason' => 'اعزام ویژه با تأیید سرپرست شیفت.',
            ]);
    }

    public function test_two_competing_acceptances_cannot_create_duplicate_assignments(): void
    {
        [$serviceId, $areaId] = $this->createServiceAndArea();
        $booking = $this->createBooking($serviceId, $areaId);
        $first = $this->createTechnician($serviceId, $areaId);
        $second = $this->createTechnician($serviceId, $areaId, 35.702, 51.4);
        $dispatcher = $this->createUser('dispatcher');
        DB::table('security_policies')->insert([
            'policy_key' => 'admin.location_retention_hours',
            'policy_value' => json_encode(['hours' => 2], JSON_THROW_ON_ERROR),
            'reason' => 'تنظیم پنجرهٔ دسترسی مکانی آزمون',
            'updated_by_user_id' => $dispatcher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($dispatcher);
        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch", [
            'technician_ids' => [$first['profile_id']],
        ])->assertCreated();

        $now = now();
        $secondOfferId = DB::table('dispatch_offers')->insertGetId([
            'booking_id' => $booking['id'],
            'technician_id' => $second['profile_id'],
            'round_number' => 1,
            'distance_km' => 0.22,
            'offered_earnings' => 85000,
            'status' => 'pending',
            'expires_at' => $now->copy()->addMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Sanctum::actingAs($first['user']);
        $firstOfferId = DB::table('dispatch_offers')->where('booking_id', $booking['id'])
            ->where('technician_id', $first['profile_id'])->value('id');
        $this->postJson("/api/technician/offers/{$firstOfferId}/respond", [
            'decision' => 'accepted',
        ])->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        Sanctum::actingAs($second['user']);
        $this->postJson("/api/technician/offers/{$secondOfferId}/respond", [
            'decision' => 'accepted',
        ])->assertConflict()
            ->assertJsonPath('error', 'BOOKING_ALREADY_ASSIGNED');

        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseHas('assignments', [
            'booking_id' => $booking['id'],
            'technician_id' => $first['profile_id'],
            'status' => 'active',
        ]);
        $this->assertEqualsWithDelta(
            now()->addHours(2)->timestamp,
            \Illuminate\Support\Carbon::parse(
                DB::table('bookings')->where('id', $booking['id'])->value('location_access_expires_at')
            )->timestamp,
            2,
        );
        try {
            DB::table('assignments')->insert([
                'booking_id' => $booking['id'],
                'technician_id' => $second['profile_id'],
                'offer_id' => $secondOfferId,
                'status' => 'active',
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('The unique booking assignment constraint must reject a competing writer.');
        } catch (QueryException) {
            $this->assertDatabaseCount('assignments', 1);
        }
    }

    public function test_unanswered_offer_expires_and_scheduler_issues_next_candidate(): void
    {
        [$serviceId, $areaId] = $this->createServiceAndArea();
        $booking = $this->createBooking($serviceId, $areaId);
        $first = $this->createTechnician($serviceId, $areaId, 35.701, 51.4);
        $second = $this->createTechnician($serviceId, $areaId, 35.702, 51.4);
        Sanctum::actingAs($this->createUser('dispatcher'));
        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch")
            ->assertCreated()
            ->assertJsonPath('data.technician_id', $first['profile_id']);
        $firstOfferId = DB::table('dispatch_offers')->where('booking_id', $booking['id'])->value('id');
        DB::table('dispatch_offers')->where('id', $firstOfferId)->update([
            'expires_at' => now()->subSecond(),
        ]);

        $this->artisan('dispatch:expire-offers')->expectsOutput('Expired 1 dispatch offers.')->assertExitCode(0);

        $this->assertDatabaseHas('dispatch_offers', [
            'id' => $firstOfferId,
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('dispatch_offers', [
            'booking_id' => $booking['id'],
            'technician_id' => $second['profile_id'],
            'status' => 'pending',
        ]);
    }

    public function test_manual_dispatch_requires_permission_and_active_qualifications(): void
    {
        [$serviceId, $areaId] = $this->createServiceAndArea();
        $booking = $this->createBooking($serviceId, $areaId);
        $technician = $this->createTechnician($serviceId, $areaId);
        Sanctum::actingAs($this->createUser('dispatcher'));
        $this->postJson("/api/admin/bookings/{$booking['id']}/assign", [
            'technician_id' => $technician['profile_id'],
            'reason' => 'تخصیص دستی برای بررسی دسترسی.',
        ])->assertForbidden();
        Sanctum::actingAs($this->createUser('operator'));
        DB::table('technician_profiles')->where('id', $technician['profile_id'])->update([
            'status' => 'suspended',
        ]);
        $this->postJson("/api/admin/bookings/{$booking['id']}/assign", [
            'technician_id' => $technician['profile_id'],
            'reason' => 'تخصیص دستی متخصص معلق.',
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'TECHNICIAN_NOT_ELIGIBLE');
        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_postgis_candidate_search_uses_region_geometry_and_geodesic_distance(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostGIS candidate search requires PostgreSQL with the PostGIS extension.');
        }
        [$serviceId, $areaId] = $this->createServiceAndArea();
        DB::table('service_areas')->where('id', $areaId)->update([
            'polygon_geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[
                    [51.39, 35.69],
                    [51.41, 35.69],
                    [51.41, 35.71],
                    [51.39, 35.71],
                    [51.39, 35.69],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $booking = $this->createBooking($serviceId, $areaId);
        $inside = $this->createTechnician($serviceId, $areaId, 35.7001, 51.4);
        $this->createTechnician($serviceId, $areaId, 35.75, 51.4);
        Sanctum::actingAs($this->createUser('dispatcher'));

        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch")
            ->assertCreated()
            ->assertJsonPath('data.technician_id', $inside['profile_id'])
            ->assertJsonPath('data.distance_method', 'postgis_geography')
            ->assertJsonPath('data.travel_time_status', 'unavailable');
    }

    private function createServiceAndArea(): array
    {
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمت اعزام فاز نه',
            'slug' => 'dispatch-category-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'خدمت اعزام آزمون',
            'slug' => 'dispatch-service-'.Str::lower(Str::random(8)),
            'base_price' => 100000,
            'estimated_duration_minutes' => 30,
            'requires_ownership_proof' => true,
            'requires_completion_evidence' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $areaId = DB::table('service_areas')->insertGetId([
            'city_name' => 'تهران',
            'zone_name' => 'محدوده اعزام',
            'slug' => 'dispatch-area-'.Str::lower(Str::random(8)),
            'center_lat' => 35.7,
            'center_lng' => 51.4,
            'radius_km' => 25,
            'minimum_trip_km' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$serviceId, $areaId];
    }

    private function createTechnician(
        int $serviceId,
        int $areaId,
        float $latitude = 35.7,
        float $longitude = 51.4,
    ): array {
        $user = $this->createUser('technician');
        $now = now();
        $profileId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $user->id,
            'status' => 'verified',
            'is_online' => true,
            'is_available' => true,
            'current_lat' => $latitude,
            'current_lng' => $longitude,
            'last_telemetry_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('technician_services')->insert([
            'technician_id' => $profileId,
            'service_id' => $serviceId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('technician_service_areas')->insert([
            'technician_id' => $profileId,
            'service_area_id' => $areaId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('technician_working_hours')->insert([
            'technician_id' => $profileId,
            'day_of_week' => $now->dayOfWeek,
            'starts_at' => '00:00:00',
            'ends_at' => '23:59:59',
            'capacity' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['user' => $user, 'profile_id' => $profileId];
    }

    private function createBooking(int $serviceId, int $areaId, float $latitude = 35.7, float $longitude = 51.4): array
    {
        $customer = $this->createUser('customer');
        $customerProfileId = DB::table('customer_profiles')->insertGetId([
            'user_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $estimateId = DB::table('price_estimates')->insertGetId([
            'estimate_uuid' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'service_id' => $serviceId,
            'service_area_id' => $areaId,
            'pickup_lat' => $latitude,
            'pickup_lng' => $longitude,
            'estimated_distance_km' => 1,
            'base_fare' => 100000,
            'distance_fare' => 0,
            'min_total_price' => 100000,
            'max_total_price' => 110000,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bookingId = DB::table('bookings')->insertGetId([
            'booking_code' => (string) Str::uuid(),
            'customer_id' => $customerProfileId,
            'service_id' => $serviceId,
            'price_estimate_id' => $estimateId,
            'status' => 'draft',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'street_address' => 'نشانی محرمانه',
            'problem_description' => 'شرح درخواست آزمون',
            'estimated_price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('booking_status_history')->insert([
            'booking_id' => $bookingId,
            'from_status' => null,
            'to_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $bookingId];
    }

    private function createUser(string $role): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'کاربر آزمون اعزام',
            'phone_number' => '09'.random_int(100000000, 999999999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_user')->insert([
            'user_id' => $userId,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        return User::findOrFail($userId);
    }
}
