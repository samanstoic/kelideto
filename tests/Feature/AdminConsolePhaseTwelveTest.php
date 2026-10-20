<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminConsolePhaseTwelveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_dashboard_is_live_and_identity_headers_cannot_grant_admin_access(): void
    {
        $admin = $this->createUser('super_admin');
        DB::table('users')->insert([
            'name' => 'داشبورد واقعی',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = $this->createUser('customer');
        Sanctum::actingAs($customer);

        $this->withHeader('X-User-Id', (string) $admin->id)
            ->getJson('/api/admin/console/dashboard')
            ->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/console/dashboard')
            ->assertOk()
            ->assertJsonPath('data.users', 3)
            ->assertJsonPath('data.active_users', 3);
    }

    public function test_admin_user_changes_require_step_up_and_are_audited_with_reason(): void
    {
        $admin = $this->createUser('super_admin', 'admin-secret');
        $target = $this->createUser('customer');
        Sanctum::actingAs($admin);

        $payload = [
            'status' => 'suspended',
            'reason' => 'درخواست رسمی بررسی حساب',
            'password' => 'incorrect',
            'confirmation' => 'CONFIRM',
        ];
        $this->patchJson("/api/admin/console/users/{$target->id}", $payload)->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'active']);
        $this->assertDatabaseMissing('audit_logs', ['auditable_type' => 'user', 'auditable_id' => $target->id]);

        $payload['password'] = 'admin-secret';
        $this->patchJson("/api/admin/console/users/{$target->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.user.updated',
            'auditable_type' => 'user',
            'auditable_id' => $target->id,
            'reason' => $payload['reason'],
        ]);
    }

    public function test_passwordless_admin_can_step_up_only_with_a_recent_authenticated_token(): void
    {
        $admin = $this->createUser('super_admin');
        DB::table('users')->where('id', $admin->id)->update(['password' => null]);
        $target = $this->createUser('customer');
        $token = $admin->createToken('recent-login')->plainTextToken;

        $this->withToken($token)->patchJson("/api/admin/console/users/{$target->id}", [
            'status' => 'suspended',
            'reason' => 'بررسی امنیتی حساب کاربری',
            'confirmation' => 'CONFIRM',
        ])->assertOk();
    }

    public function test_auth_me_ignores_identity_headers_and_returns_only_the_authenticated_user(): void
    {
        $admin = $this->createUser('super_admin');
        $customer = $this->createUser('customer');
        Sanctum::actingAs($customer);

        $this->withHeader('X-User-Id', (string) $admin->id)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);
    }

    public function test_generic_catalog_crud_is_paginated_searchable_and_audited(): void
    {
        $admin = $this->createUser('super_admin', 'admin-secret');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/console/resources/categories', [
            'name_fa' => 'قفل آزمون',
            'slug' => 'test-lock-category',
            'reason' => 'ایجاد دسته‌بندی جدید',
        ])->assertCreated()->assertJsonPath('data.name_fa', 'قفل آزمون');

        $this->getJson('/api/admin/console/resources/categories?q=آزمون&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.data.0.slug', 'test-lock-category');
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.resource.created',
            'auditable_type' => 'service_categories',
        ]);
    }

    public function test_general_settings_require_step_up_and_reject_credentials(): void
    {
        $admin = $this->createUser('super_admin', 'admin-secret');
        Sanctum::actingAs($admin);
        $base = [
            'setting_key' => 'site.contact',
            'setting_value' => ['support_email' => 'help@example.test'],
            'reason' => 'ثبت تنظیم عمومی پشتیبانی',
            'password' => 'admin-secret',
            'confirmation' => 'CONFIRM',
        ];
        $this->postJson('/api/admin/console/resources/settings', $base)
            ->assertCreated()
            ->assertJsonPath('data.setting_key', 'site.contact');

        $base['setting_key'] = 'site.integration';
        $base['setting_value'] = ['api_token' => 'must-not-be-stored'];
        $this->postJson('/api/admin/console/resources/settings', $base)
            ->assertUnprocessable();
        $this->assertDatabaseMissing('app_settings', ['setting_key' => 'site.integration']);
    }

    public function test_operations_map_exposes_only_recent_active_missions(): void
    {
        $admin = $this->createUser('super_admin');
        $customer = $this->createUser('customer');
        $technician = $this->createUser('technician');
        $customerProfile = DB::table('customer_profiles')->insertGetId([
            'user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $category = DB::table('service_categories')->insertGetId([
            'name_fa' => 'دسته', 'slug' => 'map-category', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = DB::table('services')->insertGetId([
            'category_id' => $category, 'title_fa' => 'خدمت', 'slug' => 'map-service',
            'base_price' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $technicianProfile = DB::table('technician_profiles')->insertGetId([
            'user_id' => $technician->id, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            ['status' => 'en_route', 'assigned_at' => now(), 'booking_code' => (string) Str::uuid()],
            ['status' => 'en_route', 'assigned_at' => now()->subHours(9), 'booking_code' => (string) Str::uuid()],
            ['status' => 'assigned', 'assigned_at' => now(), 'booking_code' => (string) Str::uuid()],
        ] as $mission) {
            $bookingId = DB::table('bookings')->insertGetId([
                'booking_code' => $mission['booking_code'],
                'customer_id' => $customerProfile,
                'technician_id' => $technicianProfile,
                'service_id' => $service,
                'status' => $mission['status'],
                'latitude' => 35.7,
                'longitude' => 51.4,
                'street_address' => 'موقعیت محرمانه',
                'estimated_price' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('assignments')->insert([
                'booking_id' => $bookingId,
                'technician_id' => $technicianProfile,
                'status' => 'active',
                'assigned_at' => $mission['assigned_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/console/operations-map')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.street_address', 'موقعیت محرمانه');
    }

    private function createUser(string $role, string $password = 'password'): User
    {
        $user = User::factory()->create([
            'status' => 'active',
            'password' => Hash::make($password),
        ]);
        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        return $user;
    }
}
