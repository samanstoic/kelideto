<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Tests\TestCase;

class LocationPhaseFiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('auth.guards.sanctum')) {
            $this->app->register(SanctumServiceProvider::class);
        }
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    public function test_draft_sync_validates_coordinate_range_and_seven_decimal_precision(): void
    {
        $customer = $this->createUserWithRole('customer');
        Sanctum::actingAs($customer);
        $payload = $this->draftPayload();

        $this->withHeader('X-Idempotency-Key', $payload['idempotency_key'])
            ->postJson('/api/bookings/draft-sync', array_replace($payload, ['lat' => 91]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat']);

        $this->withHeader('X-Idempotency-Key', $payload['idempotency_key'])
            ->postJson('/api/bookings/draft-sync', array_replace($payload, ['lng' => '51.12345678']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lng']);

        $this->assertDatabaseCount('offline_sync_records', 0);
    }

    public function test_draft_sync_persists_coordinates_once_for_matching_idempotency_key(): void
    {
        $customer = $this->createUserWithRole('customer');
        Sanctum::actingAs($customer);
        $payload = $this->draftPayload([
            'lat' => 35.7000001,
            'lng' => 51.4000002,
        ]);

        $this->withHeader('X-Idempotency-Key', $payload['idempotency_key'])
            ->postJson('/api/bookings/draft-sync', $payload)
            ->assertCreated()
            ->assertJsonPath('already_synced', false);

        $this->withHeader('X-Idempotency-Key', $payload['idempotency_key'])
            ->postJson('/api/bookings/draft-sync', $payload)
            ->assertOk()
            ->assertJsonPath('already_synced', true);

        $this->assertDatabaseCount('offline_sync_records', 1);
        $record = DB::table('offline_sync_records')->first();
        $stored = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(35.7000001, $stored['lat']);
        $this->assertSame(51.4000002, $stored['lng']);
    }

    public function test_synced_offline_draft_payload_is_deleted_after_its_retention_period(): void
    {
        $customer = $this->createUserWithRole('customer');
        Sanctum::actingAs($customer);
        $payload = $this->draftPayload();

        $this->withHeader('X-Idempotency-Key', $payload['idempotency_key'])
            ->postJson('/api/bookings/draft-sync', $payload)
            ->assertCreated();

        $record = DB::table('offline_sync_records')->first();
        $expiresAt = \Illuminate\Support\Carbon::parse($record->expires_at);
        $this->assertTrue($expiresAt->greaterThan(now()->addDays(6)->addHours(23)));
        $this->assertTrue($expiresAt->lessThan(now()->addDays(7)->addMinutes(1)));

        DB::table('offline_sync_records')->where('id', $record->id)
            ->update(['expires_at' => now()->subSecond()]);
        Artisan::call('offline-drafts:purge-expired');

        $this->assertDatabaseCount('offline_sync_records', 0);
    }

    public function test_reusing_a_draft_idempotency_key_with_different_content_is_a_conflict(): void
    {
        Sanctum::actingAs($this->createUserWithRole('customer'));
        $payload = $this->draftPayload();
        $this->withHeader('X-Idempotency-Key', $payload['idempotency_key'])
            ->postJson('/api/bookings/draft-sync', $payload)
            ->assertCreated();

        $changedPayload = array_replace($payload, ['notes' => 'توضیح متفاوت']);
        $this->withHeader('X-Idempotency-Key', $payload['idempotency_key'])
            ->postJson('/api/bookings/draft-sync', $changedPayload)
            ->assertStatus(409)
            ->assertJsonPath('error', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertDatabaseCount('offline_sync_records', 1);
    }

    public function test_location_search_requires_auth_and_limits_nominatim_globally(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '35.70000019',
                'lon' => '51.40000029',
                'display_name' => 'تهران، ایران',
            ]], 200),
        ]);

        $this->postJson('/api/location/search', ['query' => 'تهران'])
            ->assertUnauthorized();

        Sanctum::actingAs($this->createUserWithRole('customer'));
        $this->postJson('/api/location/search', ['query' => 'تهران'])
            ->assertOk()
            ->assertJsonPath('data.lat', 35.7000002)
            ->assertJsonPath('data.lng', 51.4000003)
            ->assertJsonPath('data.address', 'تهران، ایران');

        Cache::put('keypro:geocoding:global-rate-limit', true, now()->addSeconds(5));
        $this->postJson('/api/location/search', ['query' => 'تهران، یک نشانی دیگر'])
            ->assertStatus(429)
            ->assertHeader('Retry-After', '1');

        $this->postJson('/api/location/search', ['query' => 'تهران'])
            ->assertOk()
            ->assertJsonPath('data.address', 'تهران، ایران');
        Http::assertSentCount(1);
        $cacheKey = 'keypro:geocoding:result:' .
            hash('sha256', "https://nominatim.openstreetmap.org/search\nتهران");
        $this->assertIsArray(Cache::get($cacheKey));
        Http::assertSent(static function ($request): bool {
            $url = parse_url($request->url());
            parse_str($url['query'] ?? '', $query);

            return ($url['host'] ?? null) === 'nominatim.openstreetmap.org' &&
                ($url['path'] ?? null) === '/search' &&
                ($query['format'] ?? null) === 'jsonv2' &&
                ($query['limit'] ?? null) === '1' &&
                ($query['q'] ?? null) === 'تهران' &&
                str_contains(implode(' ', (array) $request->header('User-Agent')), 'KeyPro/1.0');
        });
    }

    public function test_only_the_verified_assigned_technician_with_location_permission_can_view_booking_coordinates(): void
    {
        $technician = $this->createUserWithRole('technician');
        $technicianProfileId = $this->createTechnicianProfile($technician, 'verified');
        $otherTechnician = $this->createUserWithRole('technician');
        $this->createTechnicianProfile($otherTechnician, 'verified');
        $bookingId = $this->createBooking($technicianProfileId);

        Sanctum::actingAs($technician);
        $this->getJson("/api/bookings/{$bookingId}/customer-location")
            ->assertOk()
            ->assertJsonPath('data.latitude', 35.7000001)
            ->assertJsonPath('data.longitude', 51.4000002);

        Sanctum::actingAs($otherTechnician);
        $this->getJson("/api/bookings/{$bookingId}/customer-location")
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN_BOOKING_LOCATION');
    }

    public function test_assigned_technician_without_location_permission_is_denied(): void
    {
        $technician = $this->createUserWithRole('technician');
        $profileId = $this->createTechnicianProfile($technician, 'verified');
        $bookingId = $this->createBooking($profileId);
        $roleId = DB::table('roles')->where('name', 'technician')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'bookings.view_assigned_location')->value('id');
        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();
        Sanctum::actingAs($technician);

        $this->getJson("/api/bookings/{$bookingId}/customer-location")
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN_BOOKING_LOCATION');
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

        return User::findOrFail($userId);
    }

    private function createTechnicianProfile(User $user, string $status): int
    {
        return DB::table('technician_profiles')->insertGetId([
            'user_id' => $user->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBooking(int $technicianProfileId): int
    {
        $customer = $this->createUserWithRole('customer');
        $customerProfileId = DB::table('customer_profiles')->insertGetId([
            'user_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمت آزمایشی',
            'slug' => 'service-' . Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'خدمت آزمایشی',
            'slug' => 'service-item-' . Str::random(8),
            'base_price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bookingId = DB::table('bookings')->insertGetId([
            'booking_code' => (string) Str::uuid(),
            'customer_id' => $customerProfileId,
            'technician_id' => $technicianProfileId,
            'service_id' => $serviceId,
            'status' => 'en_route',
            'location_access_expires_at' => now()->addHours(12),
            'latitude' => 35.7000001,
            'longitude' => 51.4000002,
            'street_address' => 'نشانی آزمایشی',
            'estimated_price' => 100000,
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

        return $bookingId;
    }

    private function draftPayload(array $overrides = []): array
    {
        return array_replace([
            'idempotency_key' => (string) Str::uuid(),
            'service_id' => 'pending-selection',
            'service_name' => 'درخواست اولیه',
            'lat' => 35.7,
            'lng' => 51.4,
            'address' => 'تهران',
            'notes' => '',
            'phone' => '',
            'created_at' => now()->toIso8601String(),
        ], $overrides);
    }
}
