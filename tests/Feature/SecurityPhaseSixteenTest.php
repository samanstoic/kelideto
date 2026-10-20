<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Adapters\MockSmsGatewayAdapter;
use App\Modules\Identity\Contracts\SmsGatewayInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityPhaseSixteenTest extends TestCase
{
    use RefreshDatabase;

    private ?string $capturedOtpCode = null;

    private ?string $capturedOtpPhoneNumber = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.initial_setup_token' => str_repeat('s', 48)]);
        $smsGatewayMock = \Mockery::mock(SmsGatewayInterface::class);
        $smsGatewayMock->shouldReceive('sendOtp')
            ->andReturnUsing(function (string $phoneNumber, string $code): bool {
                $this->capturedOtpCode = $code;
                $this->capturedOtpPhoneNumber = $phoneNumber;

                return true;
            });
        $this->app->instance(SmsGatewayInterface::class, $smsGatewayMock);
    }

    public function test_admin_account_cannot_authenticate_through_customer_otp(): void
    {
        $this->postJson('/api/setup/complete', $this->setupPayload())->assertCreated();
        $admin = User::query()->where('username', 'root_manager')->firstOrFail();
        DB::table('users')->where('id', $admin->id)->update(['phone_number' => '09123456789']);

        $this->postJson('/api/auth/otp/request', ['phone_number' => '09123456789'])->assertOk();
        $this->assertSame('09123456789', $this->capturedOtpPhoneNumber);
        $this->postJson('/api/auth/otp/verify', [
            'phone_number' => '09123456789',
            'code' => $this->capturedOtpCode,
        ])->assertForbidden()
            ->assertJsonPath('error', 'ADMIN_OTP_LOGIN_DISABLED')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'auth.admin_otp_login_blocked',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotNull(DB::table('otp_verifications')
            ->where('phone_number', '09123456789')->value('verified_at'));
    }

    public function test_suspended_account_cannot_authenticate_with_otp(): void
    {
        $user = $this->createUser('suspended_customer');
        DB::table('users')->where('id', $user->id)->update([
            'phone_number' => '09123456780',
            'status' => 'suspended',
        ]);
        $this->assignRole($user, 'customer');
        $this->postJson('/api/auth/otp/request', ['phone_number' => '09123456780'])->assertOk();
        $this->assertSame('09123456780', $this->capturedOtpPhoneNumber);

        $this->postJson('/api/auth/otp/verify', [
            'phone_number' => '09123456780',
            'code' => $this->capturedOtpCode,
        ])->assertForbidden()
            ->assertJsonPath('error', 'ACCOUNT_INACTIVE')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'user.authenticated_otp_blocked',
        ]);
    }

    public function test_mock_sms_gateway_fails_closed_in_production(): void
    {
        config(['app.env' => 'production']);

        $this->assertFalse((new MockSmsGatewayAdapter)->sendOtp('09123456789', '123456'));
    }

    public function test_expired_access_tokens_are_rejected(): void
    {
        config(['sanctum.expiration' => 1]);
        $user = $this->createUser();
        $token = $user->createToken('short-lived')->plainTextToken;

        $this->travel(2)->minutes();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_access_token(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('logout-test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_status_or_role_changes_revoke_the_target_users_tokens(): void
    {
        $admin = $this->createUser('security_admin', 'admin-password');
        $this->assignRole($admin, 'super_admin');
        $suspendedUser = $this->createUser('suspended_user');
        $this->assignRole($suspendedUser, 'technician');
        DB::table('technician_profiles')->insert([
            'user_id' => $suspendedUser->id,
            'status' => 'verified',
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 35.7,
            'current_lng' => 51.4,
            'last_telemetry_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $suspendedToken = $suspendedUser->createToken('before-suspension')->plainTextToken;
        $roleChangedUser = $this->createUser('role_changed_user');
        $roleChangedToken = $roleChangedUser->createToken('before-role-change')->plainTextToken;
        $adminToken = $admin->createToken('admin-login')->plainTextToken;

        $this->withToken($adminToken)->patchJson("/api/admin/console/users/{$suspendedUser->id}", [
            'status' => 'suspended',
            'reason' => 'تعلیق پس از بررسی امنیتی',
            'password' => 'admin-password',
            'confirmation' => 'CONFIRM',
        ])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $suspendedUser->id]);
        $this->assertDatabaseHas('technician_profiles', [
            'user_id' => $suspendedUser->id,
            'is_online' => false,
            'is_available' => false,
            'current_lat' => null,
            'current_lng' => null,
            'last_telemetry_at' => null,
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($suspendedToken)->getJson('/api/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->patchJson("/api/admin/console/users/{$roleChangedUser->id}", [
            'roles' => ['technician'],
            'reason' => 'اصلاح دسترسی پس از بازبینی',
            'password' => 'admin-password',
            'confirmation' => 'CONFIRM',
        ])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $roleChangedUser->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($roleChangedToken)->getJson('/api/auth/me')->assertUnauthorized();
    }

    private function createUser(string $username = 'test_user', ?string $password = null): User
    {
        return User::query()->create([
            'name' => $username,
            'username' => $username,
            'password' => $password ?? 'a-strong-test-password',
        ]);
    }

    private function assignRole(User $user, string $role): void
    {
        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);
    }

    private function setupPayload(): array
    {
        return [
            'name' => 'مدیر سامانه',
            'username' => 'root_manager',
            'setup_token' => str_repeat('s', 48),
            'email' => 'root@example.test',
            'password' => 'a-very-strong-secret-password',
            'password_confirmation' => 'a-very-strong-secret-password',
            'settings' => [
                'site_name' => 'کلیدتو',
                'contact_email' => 'support@example.test',
                'support_phone' => '09123456789',
                'timezone' => 'Asia/Tehran',
                'dispatch_response_window_seconds' => 120,
                'location_retention_hours' => 3,
            ],
        ];
    }
}
