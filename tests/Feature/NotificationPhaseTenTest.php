<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationJob;
use App\Models\User;
use App\Modules\Booking\Services\BookingLifecycleService;
use App\Modules\Identity\Contracts\SmsGatewayInterface;
use App\Modules\Notifications\Services\NotificationDeliveryService;
use App\Modules\Notifications\Services\NotificationService;
use Database\Seeders\NotificationTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotificationPhaseTenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(NotificationTemplatesSeeder::class);
    }

    public function test_booking_status_changes_enqueue_idempotent_notifications_after_commit(): void
    {
        Bus::fake();
        $customer = $this->createUser('customer');
        $bookingId = $this->createBooking($customer);

        $lifecycle = app(BookingLifecycleService::class);
        $lifecycle->transition($bookingId, 'broadcasting', $customer->id);
        $lifecycle->transition($bookingId, 'broadcasting', $customer->id);

        $this->assertDatabaseCount('notifications_log', 2);
        $this->assertDatabaseHas('notifications_log', [
            'user_id' => $customer->id,
            'channel' => 'in_app',
            'event_key' => 'booking.status_changed',
            'provider_status' => 'accepted',
            'delivery_status' => 'delivered',
        ]);
        $this->assertDatabaseHas('notifications_log', [
            'user_id' => $customer->id,
            'channel' => 'sms',
            'event_key' => 'booking.status_changed',
            'provider_status' => 'queued',
        ]);
        Bus::assertDispatched(DeliverNotificationJob::class, 1);

        $notification = DB::table('notifications_log')->where('channel', 'sms')->first();
        $this->assertStringNotContainsString('نشانی محرمانه', $notification->body);
        $this->assertStringNotContainsString('35.7', $notification->body);
        $this->assertStringNotContainsString('51.4', $notification->body);
    }

    public function test_duplicate_events_are_suppressed_and_hourly_preferences_limit_queued_messages(): void
    {
        Bus::fake();
        $customer = $this->createUser('customer');
        $bookingId = $this->createBooking($customer);
        Sanctum::actingAs($customer);

        $this->putJson('/api/notifications/preferences', [
            'event_key' => 'booking.status_changed',
            'channel' => 'sms',
            'enabled' => true,
            'hourly_limit' => 1,
        ])->assertOk();

        $notifications = app(NotificationService::class);
        $notifications->notifyBooking($bookingId, 'booking.status_changed', [], 'status:test:first');
        $notifications->notifyBooking($bookingId, 'booking.status_changed', [], 'status:test:first');
        $notifications->notifyBooking($bookingId, 'booking.status_changed', [], 'status:test:second');

        $this->assertSame(1, DB::table('notifications_log')->where('channel', 'sms')
            ->where('provider_status', 'queued')->count());
        $this->assertSame(1, DB::table('notifications_log')->where('channel', 'sms')
            ->where('provider_status', 'suppressed')->count());
        $this->assertSame(2, DB::table('notifications_log')->where('channel', 'in_app')->count());
        Bus::assertDispatched(DeliverNotificationJob::class, 1);
    }

    public function test_dispatch_offer_notification_is_sent_only_to_the_assigned_candidate(): void
    {
        Bus::fake();
        $customer = $this->createUser('customer');
        $technician = $this->createUser('technician');
        $bookingId = $this->createBooking($customer, 'broadcasting');
        $profileId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $technician->id,
            'status' => 'verified',
            'is_online' => true,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $offerId = DB::table('dispatch_offers')->insertGetId([
            'booking_id' => $bookingId,
            'technician_id' => $profileId,
            'round_number' => 1,
            'distance_km' => 2.1,
            'offered_earnings' => 85000,
            'status' => 'pending',
            'expires_at' => now()->addMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(NotificationService::class)->notifyTechnicianOffer($offerId);

        $this->assertDatabaseHas('notifications_log', [
            'user_id' => $technician->id,
            'event_key' => 'dispatch.offer.issued',
            'channel' => 'in_app',
            'delivery_status' => 'delivered',
        ]);
        $this->assertDatabaseMissing('notifications_log', [
            'user_id' => $customer->id,
            'event_key' => 'dispatch.offer.issued',
        ]);
    }

    public function test_customer_cancellation_queues_a_cancellation_notice_without_exposing_its_reason(): void
    {
        Bus::fake();
        $customer = $this->createUser('customer');
        $bookingId = $this->createBooking($customer);
        $customerProfileId = DB::table('customer_profiles')->where('user_id', $customer->id)->value('id');

        app(BookingLifecycleService::class)->cancelByCustomer(
            $bookingId,
            (int) $customerProfileId,
            (int) $customer->id,
            'نشانی خانه تغییر کرده است.',
        );

        $notification = DB::table('notifications_log')
            ->where('user_id', $customer->id)
            ->where('event_key', 'booking.cancelled')
            ->where('channel', 'in_app')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringNotContainsString('نشانی', $notification->body);
        $this->assertSame('delivered', $notification->delivery_status);
    }

    public function test_failed_provider_attempt_is_recorded_and_retried_without_claiming_delivery(): void
    {
        $notificationId = DB::table('notifications_log')->insertGetId([
            'user_id' => $this->createUser('customer')->id,
            'channel' => 'sms',
            'recipient' => '09123456789',
            'template_key' => 'booking.status_changed',
            'event_key' => 'booking.status_changed',
            'idempotency_key' => hash('sha256', 'retry-test'),
            'body' => 'کلیدتو: وضعیت سفارش تغییر کرد.',
            'provider' => 'mock',
            'status' => 'queued',
            'provider_status' => 'queued',
            'delivery_status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gateway = Mockery::mock(SmsGatewayInterface::class);
        $gateway->shouldReceive('sendNotification')->once()->andReturn(false);
        $this->app->instance(SmsGatewayInterface::class, $gateway);
        config([
            'services.sms.driver' => 'kavenegar',
            'services.kavenegar.api_key' => 'test-api-key',
        ]);
        $delivery = app(NotificationDeliveryService::class);

        try {
            $delivery->deliver($notificationId);
            $this->fail('A rejected SMS provider attempt must throw so the queue can retry it.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SMS provider did not accept the notification.', $exception->getMessage());
        }

        $this->assertDatabaseHas('notifications_log', [
            'id' => $notificationId,
            'attempts' => 1,
            'provider_status' => 'retrying',
            'delivery_status' => 'unknown',
        ]);
        $job = new DeliverNotificationJob($notificationId);
        $this->assertSame([30, 120, 600, 1800], $job->backoff());
        $delivery->markFailed($notificationId, new RuntimeException('provider unavailable'));
        $this->assertDatabaseHas('notifications_log', [
            'id' => $notificationId,
            'provider_status' => 'failed',
            'delivery_status' => 'unknown',
        ]);
    }

    public function test_provider_acceptance_is_not_reported_as_end_user_delivery(): void
    {
        $customer = $this->createUser('customer');
        $notificationId = DB::table('notifications_log')->insertGetId([
            'user_id' => $customer->id,
            'channel' => 'sms',
            'recipient' => $customer->phone_number,
            'template_key' => 'booking.status_changed',
            'event_key' => 'booking.status_changed',
            'idempotency_key' => hash('sha256', 'accepted-test'),
            'body' => 'کلیدتو: وضعیت سفارش تغییر کرد.',
            'provider' => 'mock',
            'status' => 'queued',
            'provider_status' => 'queued',
            'delivery_status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gateway = Mockery::mock(SmsGatewayInterface::class);
        $gateway->shouldReceive('sendNotification')->once()->andReturn(true);
        $this->app->instance(SmsGatewayInterface::class, $gateway);
        config([
            'services.sms.driver' => 'kavenegar',
            'services.kavenegar.api_key' => 'test-api-key',
        ]);

        app(NotificationDeliveryService::class)->deliver($notificationId);

        $this->assertDatabaseHas('notifications_log', [
            'id' => $notificationId,
            'provider_status' => 'accepted',
            'delivery_status' => 'unknown',
            'status' => 'sent',
        ]);
    }

    public function test_mock_sms_transport_is_reported_disconnected_instead_of_accepted(): void
    {
        $notificationId = DB::table('notifications_log')->insertGetId([
            'user_id' => $this->createUser('customer')->id,
            'channel' => 'sms',
            'recipient' => '09123456789',
            'template_key' => 'booking.status_changed',
            'event_key' => 'booking.status_changed',
            'idempotency_key' => hash('sha256', 'mock-disconnected'),
            'body' => 'کلیدتو: وضعیت سفارش تغییر کرد.',
            'provider' => 'mock',
            'status' => 'queued',
            'provider_status' => 'queued',
            'delivery_status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gateway = Mockery::mock(SmsGatewayInterface::class);
        $gateway->shouldNotReceive('sendNotification');
        $this->app->instance(SmsGatewayInterface::class, $gateway);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMS provider is disconnected; real credentials are not configured.');
        app(NotificationDeliveryService::class)->deliver($notificationId);
    }

    public function test_notification_inbox_and_provider_status_are_scoped_and_authorized(): void
    {
        $owner = $this->createUser('customer');
        $other = $this->createUser('customer');
        $notificationId = DB::table('notifications_log')->insertGetId([
            'user_id' => $owner->id,
            'channel' => 'in_app',
            'recipient' => (string) $owner->id,
            'template_key' => 'booking.status_changed',
            'event_key' => 'booking.status_changed',
            'idempotency_key' => hash('sha256', 'inbox-owner'),
            'body' => 'وضعیت سفارش تغییر کرد.',
            'payload' => json_encode(['title' => 'وضعیت سفارش', 'url' => '/orders'], JSON_THROW_ON_ERROR),
            'provider' => 'database',
            'status' => 'sent',
            'provider_status' => 'accepted',
            'delivery_status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($other);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/notifications/{$notificationId}/read")->assertNotFound();
        $this->getJson('/api/admin/notifications/services')->assertForbidden();

        $admin = $this->createUser('super_admin');
        Sanctum::actingAs($admin);
        config([
            'services.sms.driver' => 'kavenegar',
            'services.kavenegar.api_key' => null,
            'services.webpush.public_key' => null,
            'services.webpush.private_key' => null,
            'services.webpush.subject' => null,
        ]);
        $this->getJson('/api/admin/notifications/services')
            ->assertOk()
            ->assertJsonPath('data.in_app.status', 'connected')
            ->assertJsonPath('data.sms.status', 'disconnected')
            ->assertJsonPath('data.web_push.status', 'disconnected');
        $this->patchJson("/api/notifications/{$notificationId}/read")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);
    }

    public function test_web_push_requires_server_credentials_and_only_supported_endpoints(): void
    {
        $customer = $this->createUser('customer');
        Sanctum::actingAs($customer);
        config([
            'services.webpush.public_key' => null,
            'services.webpush.private_key' => null,
            'services.webpush.subject' => null,
        ]);

        $this->getJson('/api/notifications/web-push/public-key')
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'WEB_PUSH_DISCONNECTED');
        $publicKey = rtrim(strtr(base64_encode(str_repeat('p', 65)), '+/', '-_'), '=');
        $authKey = rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '=');
        $this->postJson('/api/notifications/web-push-subscriptions', [
            'consent' => true,
            'subscription' => [
                'endpoint' => 'https://example.com/subscription',
                'keys' => ['p256dh' => $publicKey, 'auth' => $authKey],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'INVALID_PUSH_ENDPOINT');
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_web_push_subscription_is_stored_only_after_explicit_consent(): void
    {
        $customer = $this->createUser('customer');
        $bookingId = $this->createBooking($customer);
        Sanctum::actingAs($customer);
        $vapid = [
            'publicKey' => 'BA6jvk34k6YjElHQ6S0oZwmrsqHdCNajxcod6KJnI77Dagikfb--O_kYXcR2eflRz6l3PcI2r8fPCH3BElLQHDk',
            'privateKey' => '-3CdhFOqjzixgAbUSa0Zv9zi-dwDVmWO7672aBxSFPQ',
        ];
        config([
            'services.webpush.public_key' => $vapid['publicKey'],
            'services.webpush.private_key' => $vapid['privateKey'],
            'services.webpush.subject' => 'mailto:support@kelideto.com',
        ]);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint';
        $publicKey = rtrim(strtr(base64_encode(str_repeat('p', 65)), '+/', '-_'), '=');
        $authKey = rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '=');

        $this->postJson('/api/notifications/web-push-subscriptions', [
            'consent' => true,
            'subscription' => [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => $publicKey, 'auth' => $authKey],
                'contentEncoding' => 'aes128gcm',
            ],
        ])->assertCreated()->assertJsonPath('data.subscribed', true);
        $this->assertDatabaseHas('web_push_subscriptions', [
            'user_id' => $customer->id,
            'endpoint_hash' => hash('sha256', $endpoint),
        ]);
        $this->assertDatabaseMissing('web_push_subscriptions', [
            'user_id' => $customer->id,
            'endpoint_hash' => $endpoint,
        ]);

        Bus::fake();
        app(NotificationService::class)->notifyBooking($bookingId, 'booking.status_changed', [], 'push-consent-test');
        $this->assertDatabaseHas('notifications_log', [
            'user_id' => $customer->id,
            'channel' => 'web_push',
            'event_key' => 'booking.status_changed',
            'provider_status' => 'queued',
        ]);
    }

    public function test_missing_sms_credentials_are_reported_instead_of_claiming_otp_was_sent(): void
    {
        config([
            'services.sms.driver' => 'kavenegar',
            'services.kavenegar.api_key' => null,
        ]);

        $this->postJson('/api/auth/otp/request', ['phone_number' => '09123456789'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'SMS_PROVIDER_UNAVAILABLE');
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    public function test_only_authorized_operator_can_change_schedule_and_notify_parties(): void
    {
        Bus::fake();
        $customer = $this->createUser('customer');
        $bookingId = $this->createBooking($customer, 'assigned');
        Sanctum::actingAs($customer);
        $this->postJson("/api/admin/bookings/{$bookingId}/schedule", [
            'event' => 'booking.delayed',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        $operator = $this->createUser('operator');
        Sanctum::actingAs($operator);
        $newTime = now()->addDay()->toIso8601String();
        $this->postJson("/api/admin/bookings/{$bookingId}/schedule", [
            'event' => 'booking.delayed',
            'scheduled_at' => $newTime,
        ])->assertOk()
            ->assertJsonPath('data.unchanged', false);
        $this->assertDatabaseHas('booking_schedule_changes', [
            'booking_id' => $bookingId,
            'event_key' => 'booking.delayed',
        ]);
        $this->assertDatabaseHas('notifications_log', [
            'user_id' => $customer->id,
            'event_key' => 'booking.delayed',
            'channel' => 'in_app',
        ]);
        $this->assertDatabaseHas('notifications_log', [
            'user_id' => $customer->id,
            'event_key' => 'booking.delayed',
            'channel' => 'sms',
        ]);
    }

    public function test_only_super_admin_can_edit_templates_and_sensitive_placeholders_are_rejected(): void
    {
        $operator = $this->createUser('operator');
        Sanctum::actingAs($operator);
        $this->getJson('/api/admin/notifications/templates')->assertForbidden();
        $this->patchJson('/api/admin/notifications/templates/booking.status_changed/sms', [
            'body_fa' => 'نشانی مشتری {{booking_code}}',
        ])->assertForbidden();

        $admin = $this->createUser('super_admin');
        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/notifications/templates/booking.status_changed/sms', [
            'body_fa' => 'نشانی مشتری {{booking_code}}',
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'UNSAFE_NOTIFICATION_TEMPLATE');
        $this->patchJson('/api/admin/notifications/templates/booking.status_changed/sms', [
            'body_fa' => 'سفارش {{booking_code}} در وضعیت {{status_label}} است.',
        ])->assertOk();
    }

    private function createBooking(User $customer, string $status = 'draft'): int
    {
        $customerProfileId = DB::table('customer_profiles')->insertGetId([
            'user_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'دسته اعلان',
            'slug' => 'notifications-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'خدمت اعلان',
            'slug' => 'notification-service-'.Str::lower(Str::random(8)),
            'base_price' => 100000,
            'estimated_duration_minutes' => 30,
            'requires_ownership_proof' => false,
            'requires_completion_evidence' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $estimateId = DB::table('price_estimates')->insertGetId([
            'estimate_uuid' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'service_id' => $serviceId,
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

        return DB::table('bookings')->insertGetId([
            'booking_code' => (string) Str::uuid(),
            'customer_id' => $customerProfileId,
            'service_id' => $serviceId,
            'price_estimate_id' => $estimateId,
            'status' => $status,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'street_address' => 'نشانی محرمانه',
            'estimated_price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(string $role): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'کاربر آزمون اعلان',
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
