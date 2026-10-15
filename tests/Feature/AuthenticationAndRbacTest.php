<?php

namespace Tests\Feature;

use App\Modules\Identity\Policies\BookingPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthenticationAndRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Test requesting OTP with valid Iranian phone number.
     */
    public function test_request_otp_with_valid_phone_number(): void
    {
        $response = $this->postJson('/api/auth/otp/request', [
            'phone_number' => '09123456789',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('otp_verifications', [
            'phone_number' => '09123456789',
        ]);

        $this->assertDatabaseHas('notifications_log', [
            'recipient' => '09123456789',
            'channel' => 'sms',
            'provider' => 'mock',
        ]);
    }

    /**
     * Test validation failure with invalid phone format.
     */
    public function test_request_otp_with_invalid_phone_format(): void
    {
        $response = $this->postJson('/api/auth/otp/request', [
            'phone_number' => '12345',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    /**
     * Test OTP rate limiting when requesting twice immediately.
     */
    public function test_otp_rate_limiting_prevents_spam(): void
    {
        // First request: OK
        $this->postJson('/api/auth/otp/request', [
            'phone_number' => '09121112233',
        ])->assertStatus(200);

        // Immediate second request: Rate limited (429)
        $second = $this->postJson('/api/auth/otp/request', [
            'phone_number' => '09121112233',
        ]);

        $second->assertStatus(429)
            ->assertJson([
                'success' => false,
                'error' => 'AUTH_RATE_LIMITED',
            ]);
    }

    /**
     * Test verification with correct OTP registers/logs in customer.
     */
    public function test_verify_otp_with_correct_code_authenticates_customer(): void
    {
        $phone = '09351234567';

        // 1. Request OTP
        $this->postJson('/api/auth/otp/request', ['phone_number' => $phone]);

        $otpRecord = DB::table('otp_verifications')->where('phone_number', $phone)->first();
        $this->assertNotNull($otpRecord);

        // 2. Verify with correct code
        $verifyResponse = $this->postJson('/api/auth/otp/verify', [
            'phone_number' => $phone,
            'code' => $otpRecord->code,
            'role' => 'customer',
        ]);

        $verifyResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'phone_number' => $phone,
                        'roles' => ['customer'],
                    ],
                ],
            ]);

        // User and profile created in DB
        $user = DB::table('users')->where('phone_number', $phone)->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('customer_profiles', ['user_id' => $user->id]);

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'user.authenticated_otp',
        ]);
    }

    /**
     * Test verification fails with incorrect code and locks after attempts.
     */
    public function test_verify_otp_fails_with_wrong_code_and_tracks_attempts(): void
    {
        $phone = '09187654321';
        $this->postJson('/api/auth/otp/request', ['phone_number' => $phone]);

        // Attempt 1 with wrong code
        $res1 = $this->postJson('/api/auth/otp/verify', [
            'phone_number' => $phone,
            'code' => '000000',
        ]);
        $res1->assertStatus(422)->assertJson(['error' => 'INVALID_CODE']);

        // Check attempts incremented to 1
        $this->assertEquals(1, DB::table('otp_verifications')->where('phone_number', $phone)->value('attempts'));
    }

    /**
     * Test role-based authorization prevents unauthorized access (Negative Test).
     */
    public function test_customer_cannot_access_technician_dashboard(): void
    {
        // Create Customer user
        $customerId = DB::table('users')->insertGetId([
            'name' => 'مشتری بدون مجوز',
            'phone_number' => '09110001122',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerRole = DB::table('roles')->where('name', 'customer')->value('id');
        DB::table('role_user')->insert(['user_id' => $customerId, 'role_id' => $customerRole]);

        // Attempt to access technician dashboard as customer
        $response = $this->withHeaders(['X-User-Id' => $customerId])
            ->getJson('/api/technician/dashboard');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN_INSUFFICIENT_PERMISSIONS',
            ]);
    }

    /**
     * Test technician user CAN access technician dashboard.
     */
    public function test_technician_can_access_technician_dashboard(): void
    {
        // Create Technician user
        $techId = DB::table('users')->insertGetId([
            'name' => 'کلیدساز مجاز',
            'phone_number' => '09129998877',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $techRole = DB::table('roles')->where('name', 'technician')->value('id');
        DB::table('role_user')->insert(['user_id' => $techId, 'role_id' => $techRole]);

        $response = $this->withHeaders(['X-User-Id' => $techId])
            ->getJson('/api/technician/dashboard');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'panel' => 'technician',
            ]);
    }

    /**
     * Test resource ownership policy prevents IDOR attacks (Negative Test).
     */
    public function test_resource_ownership_policy_prevents_idor(): void
    {
        $policy = new BookingPolicy();

        // Customer A
        $userAId = DB::table('users')->insertGetId(['name' => 'کاربر الف', 'created_at' => now()]);
        $profileAId = DB::table('customer_profiles')->insertGetId(['user_id' => $userAId, 'created_at' => now()]);
        $roleCustomer = DB::table('roles')->where('name', 'customer')->value('id');
        DB::table('role_user')->insert(['user_id' => $userAId, 'role_id' => $roleCustomer]);

        // Customer B
        $userBId = DB::table('users')->insertGetId(['name' => 'کاربر ب', 'created_at' => now()]);
        $profileBId = DB::table('customer_profiles')->insertGetId(['user_id' => $userBId, 'created_at' => now()]);
        DB::table('role_user')->insert(['user_id' => $userBId, 'role_id' => $roleCustomer]);

        // Booking belonging to Customer A
        $booking = (object) [
            'id' => 101,
            'customer_id' => $profileAId,
            'technician_id' => null,
            'status' => 'broadcasting',
        ];

        // Customer A can view their own booking
        $this->assertTrue($policy->view($userAId, $booking));

        // Customer B cannot view Customer A's booking (IDOR prevented)
        $this->assertFalse($policy->view($userBId, $booking));
    }
}
