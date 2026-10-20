<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Booking\Services\BookingLifecycleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingLifecyclePhaseSevenTest extends TestCase
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

    public function test_customer_can_create_track_cancel_and_safely_retry_a_booking(): void
    {
        $customer = $this->createCustomer();
        [$serviceId] = $this->createPricedService($customer->id);
        Sanctum::actingAs($customer);
        $estimateId = $this->createEstimate($serviceId);
        $idempotencyKey = (string) Str::uuid();
        $payload = $this->bookingPayload($estimateId, $idempotencyKey);

        $created = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.urgency', 'urgent')
            ->assertJsonPath('already_created', false);
        $bookingCode = $created->json('data.booking_code');
        $bookingId = DB::table('bookings')->where('booking_code', $bookingCode)->value('id');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $bookingCode);
        $this->assertDatabaseHas('booking_status_history', [
            'booking_id' => $bookingId,
            'from_status' => null,
            'to_status' => 'draft',
            'changed_by_user_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'street_address' => 'نشانی آزمون',
            'request_idempotency_key' => $idempotencyKey,
        ]);
        $this->assertNull(DB::table('bookings')->where('id', $bookingId)->value('scheduled_at'));

        $this->travel(16)->minutes();
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/bookings', $payload)
            ->assertOk()
            ->assertJsonPath('already_created', true)
            ->assertJsonPath('data.booking_code', $bookingCode);
        $this->assertDatabaseCount('bookings', 1);
        DB::table('services')->where('id', DB::table('bookings')->where('id', $bookingId)->value('service_id'))
            ->update(['title_fa' => 'نام جدید خدمت']);

        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/bookings', array_replace($payload, ['street_address' => 'نشانی متفاوت']))
            ->assertConflict()
            ->assertJsonPath('error', 'IDEMPOTENCY_KEY_REUSED');

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_code', $bookingCode);
        $this->getJson("/api/bookings/{$bookingCode}")
            ->assertOk()
            ->assertJsonPath('data.service_name', 'خدمت آزمایشی')
            ->assertJsonPath('data.street_address', 'نشانی آزمون')
            ->assertJsonPath('data.latitude', 35.7)
            ->assertJsonPath('data.tariff_version', 1)
            ->assertJsonPath('data.status_history.0.to_status', 'draft');

        $this->postJson("/api/bookings/{$bookingCode}/cancel", ['reason' => 'تغییر برنامه'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('already_cancelled', false);
        $this->postJson("/api/bookings/{$bookingCode}/cancel", ['reason' => 'تغییر برنامه'])
            ->assertOk()
            ->assertJsonPath('already_cancelled', true);
        $this->assertDatabaseCount('booking_status_history', 2);
        $this->getJson("/api/bookings/{$bookingCode}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.status_history.1.to_status', 'cancelled');
    }

    public function test_customers_cannot_read_each_others_bookings_or_list_entries(): void
    {
        $firstCustomer = $this->createCustomer();
        [$serviceId] = $this->createPricedService($firstCustomer->id);
        Sanctum::actingAs($firstCustomer);
        $firstBooking = $this->createBooking($this->createEstimate($serviceId));

        $secondCustomer = $this->createCustomer();
        Sanctum::actingAs($secondCustomer);
        $secondBooking = $this->createBooking($this->createEstimate($serviceId));

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_code', $secondBooking);
        $this->getJson("/api/bookings/{$firstBooking}")
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN_BOOKING_ACCESS');
        $this->postJson("/api/bookings/{$firstBooking}/cancel", ['reason' => 'لغو غیرمجاز'])
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN_BOOKING_ACCESS');
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_scheduled_booking_preserves_the_selected_time_and_urgent_requests_do_not(): void
    {
        $customer = $this->createCustomer();
        [$serviceId] = $this->createPricedService($customer->id);
        Sanctum::actingAs($customer);
        $scheduledAt = now()->addDays(2)->setTime(15, 30);
        $estimateId = $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'urgency' => 'scheduled',
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.urgency', 'scheduled')
            ->json('data.estimate_id');

        $bookingCode = $this->createBooking($estimateId);
        $booking = DB::table('bookings')->where('booking_code', $bookingCode)->first();
        $this->assertSame('scheduled', $booking->urgency);
        $this->assertNotNull($booking->scheduled_at);
        $this->assertSame(
            $scheduledAt->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($booking->scheduled_at)->format('Y-m-d H:i:s')
        );

        $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'urgency' => 'urgent',
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'URGENCY_SCHEDULE_MISMATCH');
    }

    public function test_cancellation_rules_and_status_transitions_are_enforced_by_the_domain_service(): void
    {
        $customer = $this->createCustomer();
        [$serviceId] = $this->createPricedService($customer->id);
        Sanctum::actingAs($customer);
        $bookingCode = $this->createBooking($this->createEstimate($serviceId));
        $bookingId = DB::table('bookings')->where('booking_code', $bookingCode)->value('id');
        $lifecycle = app(BookingLifecycleService::class);

        $this->assertSame(
            'INVALID_STATUS_TRANSITION',
            $lifecycle->transition($bookingId, 'completed', $customer->id)['error']
        );
        $this->assertArrayHasKey(
            'booking',
            $lifecycle->transition($bookingId, 'broadcasting', $customer->id, 'در صف بررسی')
        );
        $this->assertArrayHasKey(
            'booking',
            $lifecycle->transition($bookingId, 'assigned', $customer->id, 'تخصیص متخصص')
        );
        $this->assertArrayHasKey(
            'booking',
            $lifecycle->transition($bookingId, 'en_route', $customer->id, 'متخصص در مسیر')
        );

        $this->postJson("/api/bookings/{$bookingCode}/cancel", ['reason' => 'دیگر لازم نیست'])
            ->assertConflict()
            ->assertJsonPath('error', 'CANCELLATION_NOT_ALLOWED');
        $this->getJson("/api/bookings/{$bookingCode}")
            ->assertOk()
            ->assertJsonPath('data.status', 'en_route')
            ->assertJsonCount(4, 'data.status_history');
    }

    private function createEstimate(int $serviceId): string
    {
        return $this->postJson('/api/pricing/estimates', [
            'service_id' => $serviceId,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'urgency' => 'urgent',
        ])->assertCreated()->json('data.estimate_id');
    }

    private function createBooking(string $estimateId): string
    {
        $key = (string) Str::uuid();
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/bookings', $this->bookingPayload($estimateId, $key))
            ->assertCreated()
            ->json('data.booking_code');
    }

    private function bookingPayload(string $estimateId, string $idempotencyKey): array
    {
        return [
            'estimate_id' => $estimateId,
            'idempotency_key' => $idempotencyKey,
            'street_address' => 'نشانی آزمون',
            'problem_description' => 'شرح مشکل آزمایشی',
            'ownership_attested' => true,
            'property_type' => 'residential',
        ];
    }

    private function createPricedService(int $creatorId): array
    {
        $now = now();
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمت آزمایشی',
            'slug' => 'phase-seven-' . Str::lower(Str::random(8)),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'خدمت آزمایشی',
            'slug' => 'service-' . Str::lower(Str::random(8)),
            'base_price' => 100000,
            'estimated_duration_minutes' => 30,
            'requires_ownership_proof' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $areaId = DB::table('service_areas')->insertGetId([
            'city_name' => 'تهران',
            'zone_name' => 'محدوده آزمایشی',
            'slug' => 'area-' . Str::lower(Str::random(8)),
            'center_lat' => 35.7,
            'center_lng' => 51.4,
            'radius_km' => 25,
            'minimum_trip_km' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('service_tariffs')->insert([
            'service_id' => $serviceId,
            'service_area_id' => $areaId,
            'version' => 1,
            'base_fare' => 100000,
            'dispatch_fee' => 20000,
            'per_km_rate' => 10000,
            'per_minute_rate' => 1000,
            'minimum_total' => 0,
            'minimum_trip_km' => 0,
            'estimate_margin_percent' => 10,
            'night_multiplier' => 1,
            'night_start' => '22:00',
            'night_end' => '06:00',
            'effective_from' => $now->copy()->subDay(),
            'effective_until' => null,
            'created_by_user_id' => $creatorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$serviceId, $areaId];
    }

    private function createCustomer(): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'مشتری آزمون',
            'phone_number' => '09' . random_int(100000000, 999999999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('name', 'customer')->value('id');
        DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($userId);
    }
}
