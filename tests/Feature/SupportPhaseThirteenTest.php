<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Payment\Contracts\PaymentGatewayInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportPhaseThirteenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    public function test_review_requires_a_completed_booking_owned_by_the_customer_and_is_moderated_before_publication(): void
    {
        [$customer, $technician, $bookingId, $technicianId] = $this->createCompletedBooking();
        $otherCustomer = $this->createUser('customer');
        Sanctum::actingAs($otherCustomer);
        $this->postJson("/api/bookings/{$bookingId}/reviews", ['rating' => 5])
            ->assertNotFound();

        [$incompleteCustomer, , $incompleteBookingId] = $this->createBooking('in_progress');
        Sanctum::actingAs($incompleteCustomer);
        $this->postJson("/api/bookings/{$incompleteBookingId}/reviews", ['rating' => 4])
            ->assertConflict();

        Sanctum::actingAs($customer);
        $created = $this->postJson("/api/bookings/{$bookingId}/reviews", [
            'rating' => 4,
            'comment' => '<script>alert(1)</script>خدمت مناسب',
        ])->assertCreated()->assertJsonPath('data.moderation_status', 'pending');
        $reviewId = $created->json('data.id');
        $this->assertDatabaseHas('reviews', [
            'id' => $reviewId,
            'booking_id' => $bookingId,
            'is_published' => false,
            'comment' => 'alert(1)خدمت مناسب',
        ]);
        $this->postJson("/api/bookings/{$bookingId}/reviews", ['rating' => 5])->assertConflict();
        $this->getJson("/api/technicians/{$technicianId}/quality")
            ->assertOk()
            ->assertJsonPath('data.review_count', 0)
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.latitude');

        $support = $this->createUser('support');
        Sanctum::actingAs($support);
        $this->getJson('/api/admin/support/reviews')->assertOk()->assertJsonPath('data.data.0.id', $reviewId);
        $this->patchJson("/api/admin/support/reviews/{$reviewId}", [
            'is_published' => true,
            'reason' => 'نظر بررسی شد و قابل انتشار است.',
        ])->assertOk()->assertJsonPath('data.is_published', true);
        $this->getJson("/api/technicians/{$technicianId}/quality")
            ->assertOk()
            ->assertJsonPath('data.review_count', 1)
            ->assertJsonPath('data.average_rating', 4);
        $quality = $this->getJson('/api/admin/support/quality')->assertOk();
        $quality->assertJsonMissingPath('data.technicians.data.0.latitude')
            ->assertJsonMissingPath('data.technicians.data.0.street_address')
            ->assertJsonMissingPath('data.technicians.data.0.phone_number');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'support.review.moderated',
            'auditable_id' => $reviewId,
        ]);
    }

    public function test_ticket_ownership_private_attachments_idempotent_replies_and_audited_assignment(): void
    {
        $customer = $this->createUser('customer');
        $otherCustomer = $this->createUser('customer');
        $support = $this->createUser('support');
        $key = (string) Str::uuid();
        Sanctum::actingAs($customer);

        $this->postJson('/api/support/tickets', [
            'subject' => 'پیوست نامعتبر',
            'category' => 'other',
            'message' => 'درخواست آزمون',
            'idempotency_key' => (string) Str::uuid(),
            'attachments' => [UploadedFile::fake()->createWithContent('unsafe.svg', '<svg></svg>')],
        ])->assertUnprocessable();
        $created = $this->postJson('/api/support/tickets', [
            'subject' => 'درخواست پشتیبانی',
            'category' => 'other',
            'message' => '<b>شرح مشکل</b>',
            'idempotency_key' => $key,
            'attachments' => [UploadedFile::fake()->createWithContent('evidence.txt', 'private evidence')],
        ])->assertCreated()->assertJsonMissingPath('data.storage_path');
        $ticketCode = $created->json('data.ticket_code');
        $ticketId = DB::table('support_tickets')->where('ticket_code', $ticketCode)->value('id');
        $attachmentId = DB::table('support_ticket_attachments')->where('ticket_id', $ticketId)->value('id');
        $this->postJson('/api/support/tickets', [
            'subject' => 'درخواست پشتیبانی',
            'category' => 'other',
            'message' => '<b>شرح مشکل</b>',
            'idempotency_key' => $key,
            'attachments' => [UploadedFile::fake()->createWithContent('evidence.txt', 'private evidence')],
        ])->assertOk()->assertJsonPath('already_created', true);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_ticket_attachments', 1);

        Sanctum::actingAs($otherCustomer);
        $this->getJson("/api/support/tickets/{$ticketCode}")->assertNotFound();
        $this->get("/api/support/tickets/{$ticketCode}/attachments/{$attachmentId}")->assertNotFound();
        $this->postJson("/api/support/tickets/{$ticketCode}/messages", [
            'message' => 'پاسخ غیرمجاز',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();

        Sanctum::actingAs($customer);
        $this->postJson("/api/support/tickets/{$ticketCode}/messages", [
            'message' => 'اطلاعات تکمیلی',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.created', true);
        $this->postJson("/api/support/tickets/{$ticketCode}/messages", [
            'message' => 'اطلاعات تکمیلی',
            'idempotency_key' => DB::table('support_ticket_messages')->where('ticket_id', $ticketId)
                ->where('message', 'اطلاعات تکمیلی')->value('idempotency_key'),
        ])->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $ticketId)->count());

        $download = $this->get("/api/support/tickets/{$ticketCode}/attachments/{$attachmentId}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $cacheControl = $download->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertNotSame('', $download->streamedContent());

        Sanctum::actingAs($support);
        $this->patchJson("/api/admin/support/tickets/{$ticketCode}", [
            'status' => 'in_progress',
            'assigned_agent_id' => $otherCustomer->id,
            'reason' => 'تلاش برای ارجاع غیرمجاز',
        ])->assertUnprocessable();
        $this->patchJson("/api/admin/support/tickets/{$ticketCode}", [
            'status' => 'in_progress',
            'assigned_agent_id' => $support->id,
            'reason' => 'ارجاع برای بررسی کارشناسی',
        ])->assertOk()->assertJsonPath('data.assigned_agent_id', $support->id);
        $details = $this->getJson("/api/support/tickets/{$ticketCode}")
            ->assertOk()
            ->assertJsonPath('data.ticket.status', 'in_progress')
            ->assertJsonPath('data.history.3.event_type', 'ticket.updated');
        $this->assertStringNotContainsString('storage_path', $details->getContent());
        $this->assertStringNotContainsString('attachment_path', $details->getContent());
        $this->assertDatabaseHas('support_ticket_events', [
            'ticket_id' => $ticketId,
            'actor_user_id' => $support->id,
            'event_type' => 'ticket.updated',
            'reason' => 'ارجاع برای بررسی کارشناسی',
        ]);
    }

    public function test_booking_technician_cannot_read_customer_complaint_or_attachments(): void
    {
        [$customer, $technician, $bookingId] = $this->createBooking('completed');
        Sanctum::actingAs($customer);

        $created = $this->postJson('/api/support/tickets', [
            'booking_id' => $bookingId,
            'subject' => 'شکایت خصوصی مشتری',
            'category' => 'misconduct',
            'message' => 'شرح محرمانه برای بررسی پشتیبانی',
            'idempotency_key' => (string) Str::uuid(),
            'attachments' => [UploadedFile::fake()->createWithContent('private-evidence.txt', 'private customer evidence')],
        ])->assertCreated();
        $ticketCode = $created->json('data.ticket_code');
        $ticketId = DB::table('support_tickets')->where('ticket_code', $ticketCode)->value('id');
        $attachmentId = DB::table('support_ticket_attachments')->where('ticket_id', $ticketId)->value('id');

        Sanctum::actingAs($technician);
        $this->getJson('/api/support/tickets')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
        $this->getJson("/api/support/tickets/{$ticketCode}")->assertNotFound();
        $this->get("/api/support/tickets/{$ticketCode}/attachments/{$attachmentId}")->assertNotFound();
        $this->postJson("/api/support/tickets/{$ticketCode}/messages", [
            'message' => 'پاسخ متخصص به شکایت محرمانه',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();

        Sanctum::actingAs($customer);
        $this->getJson("/api/support/tickets/{$ticketCode}")
            ->assertOk()
            ->assertJsonPath('data.ticket.ticket_code', $ticketCode);
    }

    public function test_cost_review_requires_completed_customer_order_and_only_one_open_case(): void
    {
        [$customer, , $bookingId] = $this->createCompletedBooking();
        Sanctum::actingAs($customer);
        $created = $this->postJson('/api/support/tickets', [
            'booking_id' => $bookingId,
            'subject' => 'بازبینی هزینه',
            'category' => 'cost_review',
            'message' => 'لطفاً مبلغ نهایی را بررسی کنید.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $ticketCode = $created->json('data.ticket_code');
        $this->postJson('/api/support/tickets', [
            'booking_id' => $bookingId,
            'subject' => 'بازبینی دوباره',
            'category' => 'cost_review',
            'message' => 'درخواست تکراری',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict();
        $this->assertDatabaseCount('support_ticket_events', 1);

        $support = $this->createUser('support');
        Sanctum::actingAs($support);
        $refundDecision = [
            'decision' => 'full_refund',
            'reason' => 'پس از بررسی، استرداد کامل درخواست شد.',
            'password' => 'test-password',
            'confirmation' => 'CONFIRM',
        ];
        $this->postJson("/api/admin/support/tickets/{$ticketCode}/decision", $refundDecision)->assertForbidden();
        $admin = $this->createUser('super_admin');
        Sanctum::actingAs($admin);
        $refundDecision['password'] = 'wrong-password';
        $this->postJson("/api/admin/support/tickets/{$ticketCode}/decision", $refundDecision)->assertUnprocessable();
        $refundDecision['password'] = 'test-password';
        $this->postJson("/api/admin/support/tickets/{$ticketCode}/decision", $refundDecision)->assertConflict();
        $this->assertDatabaseCount('payment_refunds', 0);

        Sanctum::actingAs($support);
        $this->postJson("/api/admin/support/tickets/{$ticketCode}/decision", [
            'decision' => 'reject',
            'reason' => 'مدارک برای تأیید درخواست کافی نیست.',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertDatabaseHas('support_ticket_events', [
            'ticket_id' => DB::table('support_tickets')->where('ticket_code', $ticketCode)->value('id'),
            'event_type' => 'ticket.decision_recorded',
            'reason' => 'مدارک برای تأیید درخواست کافی نیست.',
        ]);
    }

    public function test_related_booking_picker_includes_only_customer_or_technician_orders_without_location_data(): void
    {
        [$customer, $technician, $bookingId] = $this->createCompletedBooking();
        Sanctum::actingAs($customer);
        $this->getJson('/api/support/bookings')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $bookingId)
            ->assertJsonMissingPath('data.data.0.latitude')
            ->assertJsonMissingPath('data.data.0.street_address');

        Sanctum::actingAs($technician);
        $this->getJson('/api/support/bookings')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $bookingId)
            ->assertJsonMissingPath('data.data.0.longitude')
            ->assertJsonMissingPath('data.data.0.street_address');

        $unrelated = $this->createUser('customer');
        Sanctum::actingAs($unrelated);
        $this->getJson('/api/support/bookings')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_full_refund_decision_uses_verified_payment_ledger_and_is_idempotently_recorded(): void
    {
        [$customer, , $bookingId] = $this->createBooking('payment_pending');
        $this->app->bind(PaymentGatewayInterface::class, fn () => new class implements PaymentGatewayInterface
        {
            public function name(): string
            {
                return 'support_test_gateway';
            }

            public function readiness(): array
            {
                return ['status' => 'connected', 'reason' => null];
            }

            public function request(int $amountMinor, string $callbackUrl, string $description, string $paymentUuid): array
            {
                return ['accepted' => true, 'authority' => 'SUPPORT-AUTH', 'redirect_url' => 'https://gateway.test/pay'];
            }

            public function verify(string $authority, int $amountMinor): array
            {
                return ['verified' => $authority === 'SUPPORT-AUTH', 'reference' => 'SUPPORT-REF', 'code' => '100'];
            }
        });

        Sanctum::actingAs($customer);
        $payment = $this->postJson("/api/bookings/{$bookingId}/payments", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
        $this->getJson("/api/payments/{$payment['payment_uuid']}/callback?Authority=SUPPORT-AUTH&Status=OK")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'completed']);

        $ticket = $this->postJson('/api/support/tickets', [
            'booking_id' => $bookingId,
            'subject' => 'بررسی هزینه پس از پرداخت',
            'category' => 'cost_review',
            'message' => 'درخواست بازبینی مبلغ پرداخت‌شده',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
        $admin = $this->createUser('super_admin');
        Sanctum::actingAs($admin);
        $decision = [
            'decision' => 'full_refund',
            'reason' => 'بازپرداخت کامل پس از بررسی مستندات تأیید شد.',
            'password' => 'test-password',
            'confirmation' => 'CONFIRM',
        ];
        $this->postJson("/api/admin/support/tickets/{$ticket['ticket_code']}/decision", $decision)
            ->assertOk()
            ->assertJsonPath('data.decision', 'full_refund')
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.refund.status', 'wallet_credited');
        $this->assertDatabaseHas('payment_refunds', [
            'payment_id' => DB::table('payments')->where('payment_uuid', $payment['payment_uuid'])->value('id'),
            'status' => 'wallet_credited',
            'reason' => $decision['reason'],
        ]);
        $this->assertDatabaseHas('support_ticket_events', [
            'ticket_id' => DB::table('support_tickets')->where('ticket_code', $ticket['ticket_code'])->value('id'),
            'actor_user_id' => $admin->id,
            'event_type' => 'ticket.decision_recorded',
            'reason' => $decision['reason'],
        ]);
        $this->postJson("/api/admin/support/tickets/{$ticket['ticket_code']}/decision", $decision)->assertConflict();
    }

    private function createCompletedBooking(string $status = 'completed'): array
    {
        return $this->createBooking($status);
    }

    private function createBooking(string $status): array
    {
        $customer = $this->createUser('customer');
        $technician = $this->createUser('technician');
        $customerProfileId = DB::table('customer_profiles')->where('user_id', $customer->id)->value('id');
        $technicianId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $technician->id,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمت پشتیبانی',
            'slug' => 'support-'.Str::lower(Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'خدمت پشتیبانی',
            'slug' => 'support-service-'.Str::lower(Str::random(8)),
            'base_price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bookingId = DB::table('bookings')->insertGetId([
            'booking_code' => (string) Str::uuid(),
            'customer_id' => $customerProfileId,
            'technician_id' => $technicianId,
            'service_id' => $serviceId,
            'status' => $status,
            'latitude' => 35.7,
            'longitude' => 51.4,
            'street_address' => 'نشانی خصوصی آزمون',
            'estimated_price' => 100000,
            'final_price' => 100000,
            'platform_commission_rate' => 15,
            'platform_commission_amount' => 15000,
            'technician_earnings' => 85000,
            'completed_at' => in_array($status, ['completed', 'payment_pending'], true) ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (in_array($status, ['completed', 'payment_pending'], true)) {
            DB::table('assignments')->insert([
                'booking_id' => $bookingId,
                'technician_id' => $technicianId,
                'status' => 'fulfilled',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$customer, $technician, $bookingId, $technicianId];
    }

    private function createUser(string $role): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'کاربر پشتیبانی آزمون',
            'phone_number' => '09'.random_int(100000000, 999999999),
            'password' => bcrypt('test-password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_user')->insert([
            'user_id' => $id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);
        if ($role === 'customer') {
            DB::table('customer_profiles')->insert([
                'user_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return User::findOrFail($id);
    }
}
