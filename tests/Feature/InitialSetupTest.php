<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.initial_setup_token' => str_repeat('s', 48)]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_setup_status_reports_server_prerequisites_without_returning_credentials(): void
    {
        config([
            'services.sms.driver' => 'mock',
            'services.payment_gateway.driver' => 'disconnected',
        ]);

        $response = $this->getJson('/api/setup/status')->assertOk()
            ->assertJsonPath('data.setup_required', true)
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.services.sms.status', 'disconnected')
            ->assertJsonPath('data.services.payment_gateway.status', 'disconnected');

        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
        $this->assertStringNotContainsString('api_key', strtolower($response->getContent()));
    }

    public function test_setup_creates_the_initial_admin_rbac_settings_policy_and_audit_atomically(): void
    {
        $this->postJson('/api/setup/complete', $this->setupPayload())
            ->assertCreated()
            ->assertJsonPath('data.setup_required', false)
            ->assertJsonPath('data.user.username', 'root_manager');

        $user = User::query()->where('username', 'root_manager')->firstOrFail();
        $this->assertTrue(Hash::check('a-very-strong-secret-password', $user->password));
        $this->assertDatabaseHas('roles', ['name' => 'super_admin']);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('name', 'super_admin')->value('id'),
        ]);
        $this->assertDatabaseHas('app_settings', [
            'setting_key' => 'dispatch.response_window_seconds',
            'setting_value' => '120',
            'updated_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('security_policies', [
            'policy_key' => 'admin.location_retention_hours',
            'policy_value' => '{"hours":3}',
            'updated_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'system.initial_setup.completed',
            'auditable_type' => 'installation',
        ]);

        $this->getJson('/api/setup/status')
            ->assertOk()
            ->assertJsonPath('data.setup_required', false);
        $this->getJson('/api/services')->assertOk();
        $this->assertSame(120, config('keypro.dispatch.response_window_seconds'));
        $this->assertSame('Asia/Tehran', config('app.timezone'));
        $this->postJson('/api/setup/complete', $this->setupPayload())
            ->assertStatus(409);
    }

    public function test_setup_rejects_short_password_and_unconfirmed_password_without_creating_admin(): void
    {
        $payload = $this->setupPayload();
        $payload['password'] = 'short';
        $payload['password_confirmation'] = 'different';

        $this->postJson('/api/setup/complete', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('installation_state', 1);
        $this->assertDatabaseHas('installation_state', ['id' => 1, 'completed_at' => null]);
    }

    public function test_admin_password_only_starts_a_five_minute_passkey_ceremony_and_never_issues_a_token(): void
    {
        $this->postJson('/api/setup/complete', $this->setupPayload())->assertCreated();

        $this->postJson('/api/auth/admin/login', [
            'username' => 'root_manager',
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $this->postJson('/api/auth/admin/login', [
            'username' => 'root_manager',
            'password' => 'a-very-strong-secret-password',
        ])->assertOk()
            ->assertJsonPath('data.mode', 'enroll')
            ->assertJsonMissingPath('data.token');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $options = $this->getJson('/api/auth/admin/passkeys/options')
            ->assertOk()
            ->assertJsonPath('options.rp.id', config('passkeys.relying_party_id'))
            ->assertJsonPath('options.authenticatorSelection.userVerification', 'required');
        $this->assertNotEmpty($options->json('options.challenge'));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/auth/admin/passkeys/register', [
            'name' => 'Browser',
            'credential' => [
                'id' => 'invalid',
                'rawId' => 'invalid',
                'type' => 'public-key',
                'response' => ['clientDataJSON' => 'invalid', 'attestationObject' => 'invalid'],
            ],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/auth/admin/passkeys/register', [
            'name' => 'Browser',
            'credential' => [
                'id' => 'invalid',
                'rawId' => 'invalid',
                'type' => 'public-key',
                'response' => ['clientDataJSON' => 'invalid', 'attestationObject' => 'invalid'],
            ],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $customer = User::query()->create([
            'name' => 'Customer',
            'username' => 'regular_customer',
            'password' => 'customer-password',
            'email' => null,
        ]);
        DB::table('role_user')->insert([
            'user_id' => $customer->id,
            'role_id' => DB::table('roles')->where('name', 'customer')->value('id'),
        ]);
        $this->postJson('/api/auth/admin/login', [
            'username' => 'regular_customer',
            'password' => 'customer-password',
        ])->assertUnprocessable();
    }

    public function test_existing_admin_passkey_options_are_scoped_to_that_admin(): void
    {
        $this->postJson('/api/setup/complete', $this->setupPayload())->assertCreated();
        $admin = User::query()->where('username', 'root_manager')->firstOrFail();
        $admin->passkeys()->create([
            'name' => 'Admin device',
            'credential_id' => 'AQID',
            'credential' => [],
        ]);

        $this->postJson('/api/auth/admin/login', [
            'username' => 'root_manager',
            'password' => 'a-very-strong-secret-password',
        ])->assertOk()->assertJsonPath('data.mode', 'verify');

        $this->getJson('/api/auth/admin/passkeys/options')
            ->assertOk()
            ->assertJsonPath('options.allowCredentials.0.id', 'AQID');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_passkey_challenge_expires_and_is_bound_to_the_login_session(): void
    {
        $this->postJson('/api/setup/complete', $this->setupPayload())->assertCreated();
        $this->postJson('/api/auth/admin/login', [
            'username' => 'root_manager',
            'password' => 'a-very-strong-secret-password',
        ])->assertOk()->assertJsonPath('data.mode', 'enroll');

        $this->travel(6)->minutes();

        $this->getJson('/api/auth/admin/passkeys/options')->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_production_passkey_origins_block_setup_readiness(): void
    {
        config([
            'passkeys.explicitly_configured' => true,
            'passkeys.relying_party_id' => 'keypro.example',
            'passkeys.allowed_origins' => ['https://attacker.example'],
        ]);

        $this->getJson('/api/setup/status')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.requirements.4.ready', false);
    }

    public function test_setup_does_not_complete_when_application_key_is_missing(): void
    {
        config(['app.key' => null]);

        $this->postJson('/api/setup/complete', $this->setupPayload())->assertServiceUnavailable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_requires_the_server_generated_one_time_token(): void
    {
        $payload = $this->setupPayload();
        $payload['setup_token'] = str_repeat('x', 48);

        $this->postJson('/api/setup/complete', $payload)->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_token_must_be_long_enough_before_the_installer_is_enabled(): void
    {
        config(['app.initial_setup_token' => 'too-short']);

        $this->getJson('/api/setup/status')
            ->assertOk()
            ->assertJsonPath('data.ready', false);
        $this->postJson('/api/setup/complete', $this->setupPayload())->assertServiceUnavailable();
    }

    public function test_setup_requires_a_cryptographically_valid_application_key(): void
    {
        config(['app.key' => 'not-a-valid-key']);

        $this->getJson('/api/setup/status')
            ->assertOk()
            ->assertJsonPath('data.ready', false);
        $this->postJson('/api/setup/complete', $this->setupPayload())->assertServiceUnavailable();
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
