<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TechnicianOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Tests\TestCase;

class TechnicianOperationsPhaseEightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('auth.guards.sanctum')) {
            $this->app->register(SanctumServiceProvider::class);
        }
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TechnicianOperationsSeeder::class);
        Storage::fake('local');
    }

    public function test_registration_and_manager_review_require_both_approved_documents(): void
    {
        $technician = $this->createUser('customer');
        Sanctum::actingAs($technician);

        $this->postJson('/api/technician/register')
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_verification');
        $profileId = DB::table('technician_profiles')->where('user_id', $technician->id)->value('id');

        $firstDocumentId = $this->uploadTechnicianDocument('national_id');
        $secondDocumentId = $this->uploadTechnicianDocument('guild_license');
        $this->getJson('/api/technician/offers')
            ->assertForbidden();
        [$serviceId, $areaId] = $this->createServiceArea();
        $booking = $this->createBooking($serviceId, $areaId);
        $dispatcher = $this->createUser('dispatcher');
        Sanctum::actingAs($dispatcher);
        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch-offers", [
            'technician_ids' => [$profileId],
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'TECHNICIAN_NOT_ELIGIBLE');
        $this->assertDatabaseCount('dispatch_offers', 0);

        $unauthorizedReviewer = $this->createUser('dispatcher');
        Sanctum::actingAs($unauthorizedReviewer);
        $this->postJson("/api/admin/technicians/{$profileId}/documents/{$firstDocumentId}/review", [
            'decision' => 'approved',
        ])->assertForbidden();

        $reviewer = $this->createUser('super_admin');
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/admin/technicians/{$profileId}/documents/{$firstDocumentId}/review", [
            'decision' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.technician_status', 'pending_verification');
        $this->postJson("/api/admin/technicians/{$profileId}/documents/{$secondDocumentId}/review", [
            'decision' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.technician_status', 'verified');
        $this->postJson("/api/admin/technicians/{$profileId}/documents/{$secondDocumentId}/review", [
            'decision' => 'approved',
        ])->assertStatus(409);

        Sanctum::actingAs($technician);
        $this->putJson('/api/technician/profile', [
            'name' => 'متخصص آزمون',
            'license_number' => 'LIC-'.Str::upper(Str::random(8)),
            'guild_registration_no' => 'GUILD-001',
            'service_ids' => [$serviceId],
            'service_area_ids' => [$areaId],
            'working_hours' => [[
                'day_of_week' => now()->dayOfWeek,
                'starts_at' => '00:00',
                'ends_at' => '23:59',
                'capacity' => 2,
                'is_active' => true,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.service_ids.0', $serviceId)
            ->assertJsonPath('data.service_area_ids.0', $areaId);
        $this->putJson('/api/technician/availability', [
            'online' => true,
            'latitude' => 35.71,
            'longitude' => 51.41,
        ])->assertOk()
            ->assertJsonPath('data.online', true);
        $this->putJson('/api/technician/availability', ['online' => false])
            ->assertOk()
            ->assertJsonPath('data.available', false);
        $this->assertDatabaseHas('technician_profiles', [
            'user_id' => $technician->id,
            'current_lat' => null,
            'current_lng' => null,
            'last_telemetry_at' => null,
        ]);

        $this->getJson('/api/technician/dashboard')
            ->assertOk()
            ->assertJsonPath('data.profile.status', 'verified')
            ->assertJsonCount(2, 'data.profile.documents');
    }

    public function test_stale_technician_telemetry_is_cleared_by_scheduled_purge(): void
    {
        $technician = $this->createUser('technician');
        $profileId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $technician->id,
            'status' => 'verified',
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 35.71,
            'current_lng' => 51.41,
            'last_telemetry_at' => now()->subMinutes(16),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('technicians:purge-stale-telemetry')
            ->expectsOutput('Cleared stale telemetry for 1 technician profiles.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('technician_profiles', [
            'id' => $profileId,
            'is_online' => false,
            'is_available' => false,
            'current_lat' => null,
            'current_lng' => null,
            'last_telemetry_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'technician.telemetry.expired',
            'auditable_type' => 'technician_profiles',
        ]);
    }

    public function test_verified_technician_can_accept_and_complete_only_assigned_mission(): void
    {
        [$technician, $profileId, $serviceId, $areaId] = $this->createVerifiedTechnician();
        $booking = $this->createBooking($serviceId, $areaId);
        $dispatcher = $this->createUser('dispatcher');
        Sanctum::actingAs($dispatcher);

        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch-offers", [
            'technician_ids' => [$profileId],
        ])->assertCreated()
            ->assertJsonCount(1, 'data.offer_ids');
        $offerId = DB::table('dispatch_offers')->where('booking_id', $booking['id'])->value('id');

        Sanctum::actingAs($technician);
        $this->getJson('/api/technician/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.latitude')
            ->assertJsonMissingPath('data.0.street_address');
        $this->getJson("/api/technician/missions/{$booking['code']}")
            ->assertNotFound();
        $this->postJson("/api/technician/offers/{$offerId}/respond", [
            'decision' => 'accepted',
        ])->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/technician/offers/{$offerId}/respond", [
            'decision' => 'accepted',
        ])->assertConflict();
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseHas('assignments', [
            'booking_id' => $booking['id'],
            'technician_id' => $profileId,
            'status' => 'active',
        ]);

        $this->getJson("/api/technician/missions/{$booking['code']}")
            ->assertOk()
            ->assertJsonPath('data.street_address', 'نشانی محرمانه آزمون')
            ->assertJsonPath('data.latitude', 35.7);

        $this->postJson("/api/technician/missions/{$booking['code']}/status", [
            'status' => 'en_route',
            'final_price' => 1,
        ])
            ->assertOk()->assertJsonPath('data.status', 'en_route');
        $this->assertNull(DB::table('bookings')->where('id', $booking['id'])->value('final_price'));
        $this->postJson("/api/technician/missions/{$booking['code']}/status", ['status' => 'arrived'])
            ->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->postJson("/api/bookings/{$booking['id']}/customer-authority/verify", ['verified' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'technician_verified');
        $this->postJson("/api/technician/missions/{$booking['code']}/status", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->post("/api/technician/missions/{$booking['code']}/evidence", [
            'evidence' => UploadedFile::fake()->image('completion.png'),
        ])->assertCreated();
        $this->postJson("/api/technician/missions/{$booking['code']}/status", ['status' => 'payment_pending'])
            ->assertOk()->assertJsonPath('data.status', 'payment_pending');

        $this->assertDatabaseHas('assignments', [
            'booking_id' => $booking['id'],
            'status' => 'fulfilled',
        ]);
        $this->assertDatabaseHas('booking_status_history', [
            'booking_id' => $booking['id'],
            'to_status' => 'payment_pending',
        ]);
        $this->getJson('/api/technician/earnings')
            ->assertOk()
            ->assertJsonPath('data.gross', 100000)
            ->assertJsonPath('data.net_earnings', 85000);
        $this->getJson("/api/technician/missions/{$booking['code']}")
            ->assertNotFound();
    }

    public function test_rejected_required_document_keeps_specialist_ineligible(): void
    {
        $technician = $this->createUser('customer');
        Sanctum::actingAs($technician);
        $this->postJson('/api/technician/register')->assertCreated();
        $profileId = DB::table('technician_profiles')->where('user_id', $technician->id)->value('id');
        $nationalId = $this->uploadTechnicianDocument('national_id');
        $this->uploadTechnicianDocument('guild_license');

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->postJson("/api/admin/technicians/{$profileId}/documents/{$nationalId}/review", [
            'decision' => 'rejected',
            'rejection_reason' => 'مدرک ناخوانا است.',
        ])->assertOk()
            ->assertJsonPath('data.technician_status', 'rejected');

        Sanctum::actingAs($technician);
        $this->putJson('/api/technician/availability', [
            'online' => true,
            'latitude' => 35.71,
            'longitude' => 51.41,
        ])->assertForbidden()
            ->assertJsonPath('error', 'TECHNICIAN_NOT_VERIFIED');
        $this->getJson('/api/technician/offers')
            ->assertForbidden();
        $this->assertDatabaseMissing('technician_profiles', [
            'id' => $profileId,
            'is_online' => true,
        ]);
    }

    public function test_revoked_verification_before_acceptance_cannot_create_assignment(): void
    {
        [$technician, $profileId, $serviceId, $areaId] = $this->createVerifiedTechnician();
        $booking = $this->createBooking($serviceId, $areaId);
        Sanctum::actingAs($this->createUser('dispatcher'));
        $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch-offers", [
            'technician_ids' => [$profileId],
        ])->assertCreated();
        $offerId = DB::table('dispatch_offers')->where('booking_id', $booking['id'])->value('id');
        DB::table('technician_profiles')->where('id', $profileId)->update([
            'status' => 'pending_verification',
            'is_online' => false,
            'is_available' => false,
        ]);

        Sanctum::actingAs($technician);
        $this->postJson("/api/technician/offers/{$offerId}/respond", [
            'decision' => 'accepted',
        ])->assertForbidden()
            ->assertJsonPath('error', 'TECHNICIAN_NOT_VERIFIED');

        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking['id'],
            'technician_id' => null,
            'location_access_expires_at' => null,
        ]);
    }

    public function test_dispatch_offers_can_be_rejected_and_expire_at_their_deadline(): void
    {
        [$technician, $profileId, $serviceId, $areaId] = $this->createVerifiedTechnician();
        [$nextTechnician, $nextTechnicianProfileId] = $this->addEligibleTechnician($serviceId, $areaId);
        $booking = $this->createBooking($serviceId, $areaId);
        Sanctum::actingAs($this->createUser('dispatcher'));
        $createOffer = fn () => $this->postJson("/api/admin/bookings/{$booking['id']}/dispatch-offers", [
            'response_window_seconds' => 30,
        ])->assertCreated();

        $createOffer();
        $firstOfferId = DB::table('dispatch_offers')->where('booking_id', $booking['id'])->value('id');
        Sanctum::actingAs($technician);
        $this->postJson("/api/technician/offers/{$firstOfferId}/respond", [
            'decision' => 'rejected',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.next_offer.technician_id', $nextTechnicianProfileId);
        $secondOfferId = DB::table('dispatch_offers')->where('booking_id', $booking['id'])
            ->where('status', 'pending')
            ->value('id');
        DB::table('dispatch_offers')->where('id', $secondOfferId)->update([
            'expires_at' => now()->subSecond(),
        ]);

        Sanctum::actingAs($nextTechnician);
        $this->postJson("/api/technician/offers/{$secondOfferId}/respond", [
            'decision' => 'accepted',
        ])->assertConflict()
            ->assertJsonPath('error', 'OFFER_EXPIRED');

        $this->assertDatabaseHas('dispatch_offers', [
            'id' => $secondOfferId,
            'status' => 'expired',
        ]);
        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_customer_location_and_evidence_are_denied_after_the_assignment_access_window_expires(): void
    {
        [$technician, $profileId, $serviceId, $areaId] = $this->createVerifiedTechnician();
        $booking = $this->createBooking($serviceId, $areaId);
        DB::table('bookings')->where('id', $booking['id'])->update([
            'status' => 'in_progress',
            'technician_id' => $profileId,
            'location_access_expires_at' => now()->subSecond(),
        ]);
        DB::table('assignments')->insert([
            'booking_id' => $booking['id'],
            'technician_id' => $profileId,
            'status' => 'active',
            'assigned_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        Sanctum::actingAs($technician);

        $this->getJson("/api/bookings/{$booking['id']}/customer-location")
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN_BOOKING_LOCATION');
        $this->getJson("/api/technician/missions/{$booking['code']}")
            ->assertForbidden()
            ->assertJsonPath('error', 'LOCATION_ACCESS_EXPIRED');
        $this->post("/api/technician/missions/{$booking['code']}/evidence", [
            'evidence' => UploadedFile::fake()->image('completion.png'),
        ])->assertForbidden()
            ->assertJsonPath('error', 'LOCATION_ACCESS_EXPIRED');
    }

    private function createVerifiedTechnician(): array
    {
        $user = $this->createUser('technician');
        $now = now();
        $profileId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $user->id,
            'status' => 'verified',
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 35.71,
            'current_lng' => 51.41,
            'last_telemetry_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        [$serviceId, $areaId] = $this->createServiceArea();
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
            'day_of_week' => now()->dayOfWeek,
            'starts_at' => '00:00:00',
            'ends_at' => '23:59:59',
            'capacity' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$user, $profileId, $serviceId, $areaId];
    }

    private function addEligibleTechnician(int $serviceId, int $areaId): array
    {
        $user = $this->createUser('technician');
        $now = now();
        $profileId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $user->id,
            'status' => 'verified',
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 35.72,
            'current_lng' => 51.42,
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

        return [$user, $profileId];
    }

    private function createBooking(int $serviceId, int $areaId): array
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
            'pickup_lat' => 35.7,
            'pickup_lng' => 51.4,
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
            'latitude' => 35.7,
            'longitude' => 51.4,
            'street_address' => 'نشانی محرمانه آزمون',
            'problem_description' => 'توضیح مشکل',
            'estimated_price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('booking_authorizations')->insert([
            'booking_id' => $bookingId,
            'customer_id' => $customerProfileId,
            'property_type' => 'residential',
            'attestation_version' => 'v1',
            'status' => 'customer_attested',
            'attested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $bookingId,
            'code' => DB::table('bookings')->where('id', $bookingId)->value('booking_code'),
        ];
    }

    private function createServiceArea(): array
    {
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمت آزمون',
            'slug' => 'technician-test-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'خدمت متخصص آزمون',
            'slug' => 'technician-service-'.Str::lower(Str::random(8)),
            'base_price' => 100000,
            'estimated_duration_minutes' => 30,
            'requires_ownership_proof' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $areaId = DB::table('service_areas')->insertGetId([
            'city_name' => 'تهران',
            'zone_name' => 'محدوده آزمون',
            'slug' => 'technician-area-'.Str::lower(Str::random(8)),
            'center_lat' => 35.7,
            'center_lng' => 51.4,
            'radius_km' => 25,
            'minimum_trip_km' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('services')->where('id', $serviceId)->update([
            'requires_completion_evidence' => true,
        ]);

        return [$serviceId, $areaId];
    }

    private function uploadTechnicianDocument(string $type): int
    {
        $response = $this->post('/api/technician/documents', [
            'document_type' => $type,
            'document' => UploadedFile::fake()->create($type.'.pdf', 20, 'application/pdf'),
        ]);

        return (int) $response->assertCreated()->json('data.id');
    }

    private function createUser(string $role): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'کاربر آزمون',
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
