<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Payment\Adapters\DisconnectedPaymentGatewayAdapter;
use App\Modules\Payment\Adapters\ZarinpalPaymentGatewayAdapter;
use App\Modules\Payment\Contracts\PaymentGatewayInterface;
use App\Modules\Payment\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialPhaseElevenTest extends TestCase
{
    use RefreshDatabase;

    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->useFakeGateway();
    }

    public function test_verified_payment_posts_balanced_idempotent_ledger_and_updates_wallet(): void
    {
        [$customer, $technician, $technicianId, $bookingId] = $this->createPayableBooking();
        Sanctum::actingAs($customer);
        $idempotencyKey = Str::uuid()->toString();

        $started = $this->postJson("/api/bookings/{$bookingId}/payments", ['idempotency_key' => $idempotencyKey])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'IRR')
            ->assertJsonPath('data.amount_minor', 500000);
        $paymentUuid = $started->json('data.payment_uuid');
        $this->assertSame('https://gateway.test/pay', $started->json('data.redirect_url'));
        $this->assertSame(
            config('app.url')."/api/payments/{$paymentUuid}/callback",
            $this->gateway->lastCallbackUrl,
        );

        $this->getJson("/api/payments/{$paymentUuid}/callback?Authority=AUTH-1&Status=OK")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->getJson("/api/payments/{$paymentUuid}/callback?Authority=AUTH-1&Status=OK")
            ->assertOk()
            ->assertJsonPath('data.already_processed', true);
        $browserReturn = $this->get("/api/payments/{$paymentUuid}/callback?Authority=AUTH-1&Status=OK", [
            'Accept' => 'text/html',
        ])->assertRedirect();
        parse_str((string) parse_url($browserReturn->headers->get('Location'), PHP_URL_QUERY), $returnQuery);
        $this->assertSame('returned', $returnQuery['payment_result']);
        $this->assertSame($paymentUuid, $returnQuery['payment_uuid']);
        $this->getJson("/api/payments/{$paymentUuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/bookings/{$bookingId}/payments", ['idempotency_key' => $idempotencyKey])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.redirect_url', null);

        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'completed']);
        $this->assertDatabaseHas('payments', [
            'payment_uuid' => $paymentUuid,
            'status' => 'paid',
            'amount_minor' => 500000,
            'currency' => 'IRR',
        ]);
        $wallet = DB::table('wallets')->where('user_id', $technician->id)->first();
        $this->assertSame(425000, (int) $wallet->balance_minor);
        $this->assertSame(2, DB::table('ledger_transactions')->count());
        $this->assertSame(5, DB::table('ledger_entries')->count());
        foreach (DB::table('ledger_transactions')->get() as $transaction) {
            $this->assertSame(
                0,
                (int) DB::table('ledger_entries')
                    ->where('ledger_transaction_id', $transaction->id)
                    ->selectRaw("SUM(CASE WHEN entry_type = 'debit' THEN amount_minor ELSE -amount_minor END) AS balance")
                    ->value('balance'),
            );
        }
        $this->assertSame(1, $this->gateway->verifyCalls);
        $this->assertDatabaseHas('payment_events', ['payment_id' => DB::table('payments')->where('payment_uuid', $paymentUuid)->value('id'), 'event_type' => 'verified']);
        $this->assertSame($technicianId, (int) DB::table('bookings')->where('id', $bookingId)->value('technician_id'));
    }

    public function test_callback_success_parameter_without_gateway_verification_never_marks_payment_paid(): void
    {
        [$customer, , , $bookingId] = $this->createPayableBooking();
        $this->gateway->verifyResult = ['verified' => false, 'error' => 'GATEWAY_VERIFICATION_REJECTED'];
        Sanctum::actingAs($customer);
        $paymentUuid = $this->startPayment($bookingId);

        $this->getJson("/api/payments/{$paymentUuid}/callback?Authority=AUTH-1&Status=OK")
            ->assertUnprocessable()
            ->assertJsonPath('error', 'PAYMENT_NOT_VERIFIED');

        $this->assertDatabaseHas('payments', ['payment_uuid' => $paymentUuid, 'status' => 'failed']);
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseHas('payment_events', ['event_type' => 'verification_rejected']);
    }

    public function test_invalid_callback_and_duplicate_initiation_are_safe_and_idempotent(): void
    {
        [$customer, , , $bookingId] = $this->createPayableBooking();
        Sanctum::actingAs($customer);
        $key = Str::uuid()->toString();
        $started = $this->postJson("/api/bookings/{$bookingId}/payments", ['idempotency_key' => $key])
            ->assertCreated();
        $paymentUuid = $started->json('data.payment_uuid');
        $this->postJson("/api/bookings/{$bookingId}/payments", ['idempotency_key' => $key])
            ->assertOk()
            ->assertJsonPath('data.payment_uuid', $paymentUuid);
        $this->postJson("/api/bookings/{$bookingId}/payments", ['idempotency_key' => Str::uuid()->toString()])
            ->assertConflict()
            ->assertJsonPath('error', 'PAYMENT_ALREADY_PENDING');
        $this->getJson("/api/payments/{$paymentUuid}/callback?Authority=OTHER&Status=OK")
            ->assertUnprocessable()
            ->assertJsonPath('error', 'INVALID_GATEWAY_CALLBACK');

        $this->assertSame(1, $this->gateway->requestCalls);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_disconnected_gateway_refuses_payment_creation(): void
    {
        [$customer, , , $bookingId] = $this->createPayableBooking();
        $this->app->instance(PaymentGatewayInterface::class, new DisconnectedPaymentGatewayAdapter);
        Sanctum::actingAs($customer);

        $this->postJson("/api/bookings/{$bookingId}/payments", ['idempotency_key' => Str::uuid()->toString()])
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'GATEWAY_NOT_CONNECTED');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_refund_posts_a_reversal_and_credits_customer_wallet_once(): void
    {
        [$customer, $technician, , $bookingId] = $this->createPayableBooking();
        Sanctum::actingAs($customer);
        $paymentUuid = $this->startPayment($bookingId);
        $this->getJson("/api/payments/{$paymentUuid}/callback?Authority=AUTH-1&Status=OK")->assertOk();
        $paymentId = (int) DB::table('payments')->where('payment_uuid', $paymentUuid)->value('id');

        $accountant = $this->createUser('accountant');
        DB::table('users')->where('id', $accountant->id)->update(['password' => bcrypt('admin-secret')]);
        $accountant->refresh();
        Sanctum::actingAs($accountant);
        $refundKey = Str::uuid()->toString();
        $this->postJson("/api/finance/payments/{$paymentId}/refund", [
            'reason' => 'خدمت مطابق توافق انجام نشد',
            'idempotency_key' => $refundKey,
            'password' => 'admin-secret',
            'confirmation' => 'CONFIRM',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'wallet_credited')
            ->assertJsonPath('data.destination', 'customer_wallet');
        $this->postJson("/api/finance/payments/{$paymentId}/refund", [
            'reason' => 'خدمت مطابق توافق انجام نشد',
            'idempotency_key' => $refundKey,
            'password' => 'admin-secret',
            'confirmation' => 'CONFIRM',
        ])->assertOk()
            ->assertJsonPath('data.already_created', true);

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'refunded']);
        $this->assertSame(500000, (int) DB::table('wallets')->where('user_id', $customer->id)->value('balance_minor'));
        $this->assertSame(0, (int) DB::table('wallets')->where('user_id', $technician->id)->value('balance_minor'));
        $this->assertSame(4, DB::table('ledger_transactions')->count());
        $this->assertSame(0, (int) DB::table('financial_accounts')->sum('balance_minor'));
    }

    public function test_settlement_reserves_funds_requires_finance_permission_and_records_payout(): void
    {
        [$customer, $technician, $technicianId, $bookingId] = $this->createPayableBooking();
        Sanctum::actingAs($customer);
        $paymentUuid = $this->startPayment($bookingId);
        $this->getJson("/api/payments/{$paymentUuid}/callback?Authority=AUTH-1&Status=OK")->assertOk();

        Sanctum::actingAs($technician);
        $idempotencyKey = Str::uuid()->toString();
        $request = $this->postJson('/api/technician/settlements', [
            'amount_minor' => 300000,
            'sheba_number' => 'IR'.str_repeat('1', 24),
            'account_holder_name' => 'متخصص آزمون',
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $settlementUuid = $request->json('data.settlement_uuid');
        $this->assertSame(125000, (int) DB::table('wallets')->where('user_id', $technician->id)->value('balance_minor'));

        $customer->refresh();
        $this->postJson("/api/finance/settlements/{$settlementUuid}/review", ['decision' => 'approve'])
            ->assertForbidden();
        $accountant = $this->createUser('accountant');
        DB::table('users')->where('id', $accountant->id)->update(['password' => bcrypt('admin-secret')]);
        $accountant->refresh();
        Sanctum::actingAs($accountant);
        $this->postJson("/api/finance/settlements/{$settlementUuid}/review", [
            'decision' => 'approve', 'password' => 'admin-secret', 'confirmation' => 'CONFIRM',
        ])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/finance/settlements/{$settlementUuid}/review", [
            'decision' => 'settled',
            'payout_reference' => 'PAY-REF-1',
            'password' => 'admin-secret',
            'confirmation' => 'CONFIRM',
        ])->assertOk()->assertJsonPath('data.status', 'settled');
        $this->assertDatabaseHas('technician_settlements', [
            'settlement_uuid' => $settlementUuid,
            'technician_id' => $technicianId,
            'status' => 'settled',
            'paya_reference' => 'PAY-REF-1',
        ]);
        $this->assertSame(125000, (int) DB::table('wallets')->where('user_id', $technician->id)->value('balance_minor'));
        $this->assertSame(0, (int) DB::table('financial_accounts')->sum('balance_minor'));
        $this->assertSame(3, DB::table('technician_settlement_events')->where('settlement_id', DB::table('technician_settlements')->where('settlement_uuid', $settlementUuid)->value('id'))->count());
        $this->getJson("/api/finance/settlements/{$settlementUuid}/events")
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->getJson('/api/finance/ledger')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_reconciliation_compares_gateway_reference_ledger_and_wallet_balances(): void
    {
        [$customer, , , $bookingId] = $this->createPayableBooking();
        Sanctum::actingAs($customer);
        $paymentUuid = $this->startPayment($bookingId);
        $this->getJson("/api/payments/{$paymentUuid}/callback?Authority=AUTH-1&Status=OK")->assertOk();

        Sanctum::actingAs($this->createUser('accountant'));
        $this->postJson('/api/finance/reconciliation', [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.payments_checked', 1)
            ->assertJsonPath('data.matched_count', 1)
            ->assertJsonPath('data.mismatch_count', 0);
        $this->assertDatabaseCount('finance_reconciliation_reports', 1);
    }

    public function test_money_conversion_and_commission_use_integer_rials(): void
    {
        $this->assertSame(101, PaymentService::legacyAmountToRials('100.50'));
        $this->assertSame(100, PaymentService::legacyAmountToRials('100.49'));
        $this->assertSame(15000, PaymentService::commission(100000, '15.00'));
        $this->assertSame(1, PaymentService::commission(1, '50'));
        $this->expectException(\RuntimeException::class);
        PaymentService::legacyAmountToRials('-1.00');
    }

    public function test_zarinpal_adapter_uses_provider_request_and_verification_endpoints_and_requires_a_test(): void
    {
        config([
            'services.payment_gateway.driver' => 'zarinpal',
            'services.payment_gateway.zarinpal.merchant_id' => 'merchant-test-id',
        ]);
        $adapter = new ZarinpalPaymentGatewayAdapter;
        $this->assertSame('configured_unverified', $adapter->readiness()['status']);
        DB::table('gateway_health_checks')->insert([
            'gateway_name' => 'zarinpal',
            'check_type' => 'manual_verification',
            'provider_reference_hash' => hash('sha256', 'verified-test-transaction'),
            'passed' => true,
            'result_code' => '100',
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame('connected', $adapter->readiness()['status']);
        Http::fake([
            'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'A000000000000000000000000000001'],
            ]),
            'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 987654],
            ]),
        ]);

        $requested = $adapter->request(
            500000,
            'https://app.example.test/api/payments/'.Str::uuid().'/callback',
            'پرداخت آزمون',
            Str::uuid()->toString(),
        );
        $this->assertTrue($requested['accepted']);
        $this->assertSame(
            'https://payment.zarinpal.com/pg/StartPay/A000000000000000000000000000001',
            $requested['redirect_url'],
        );
        $verified = $adapter->verify('A000000000000000000000000000001', 500000);
        $this->assertTrue($verified['verified']);
        $this->assertSame('987654', $verified['reference']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/payment/request.json')
            && $request['amount'] === 500000
            && $request['currency'] === 'IRR'
            && $request['merchant_id'] === 'merchant-test-id');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/payment/verify.json')
            && $request['amount'] === 500000
            && $request['authority'] === 'A000000000000000000000000000001');
    }

    public function test_finance_routes_require_authenticated_accountant_and_cannot_use_identity_header(): void
    {
        $accountant = $this->createUser('accountant');
        $this->withHeader('X-User-Id', (string) $accountant->id)
            ->getJson('/api/finance/gateway')
            ->assertUnauthorized();

        Sanctum::actingAs($accountant);
        $this->getJson('/api/finance/gateway')
            ->assertOk()
            ->assertJsonPath('data.status', 'connected');
    }

    private function startPayment(int $bookingId): string
    {
        $response = $this->postJson("/api/bookings/{$bookingId}/payments", [
            'idempotency_key' => Str::uuid()->toString(),
        ])->assertCreated();

        return $response->json('data.payment_uuid');
    }

    private function createPayableBooking(): array
    {
        $customer = $this->createUser('customer');
        $technician = $this->createUser('technician');
        $customerProfileId = DB::table('customer_profiles')->insertGetId([
            'user_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $technicianId = DB::table('technician_profiles')->insertGetId([
            'user_id' => $technician->id,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمات مالی',
            'slug' => 'finance-'.Str::lower(Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('services')->insertGetId([
            'category_id' => $categoryId,
            'title_fa' => 'آزمون مالی',
            'slug' => 'finance-service-'.Str::lower(Str::random(8)),
            'base_price' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bookingId = DB::table('bookings')->insertGetId([
            'booking_code' => (string) Str::uuid(),
            'customer_id' => $customerProfileId,
            'technician_id' => $technicianId,
            'service_id' => $serviceId,
            'status' => 'payment_pending',
            'latitude' => 35.7,
            'longitude' => 51.4,
            'street_address' => 'نشانی آزمون',
            'estimated_price' => 500000,
            'final_price' => 500000,
            'platform_commission_rate' => 15,
            'platform_commission_amount' => 75000,
            'technician_earnings' => 425000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$customer, $technician, $technicianId, $bookingId];
    }

    private function createUser(string $role): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'کاربر مالی آزمون',
            'phone_number' => '09'.random_int(100000000, 999999999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_user')->insert([
            'user_id' => $id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        return User::findOrFail($id);
    }

    private function useFakeGateway(): void
    {
        $this->gateway = new class implements PaymentGatewayInterface
        {
            public int $requestCalls = 0;

            public int $verifyCalls = 0;

            public ?string $lastCallbackUrl = null;

            public array $verifyResult = ['verified' => true, 'reference' => 'REF-1', 'code' => '100'];

            public function name(): string
            {
                return 'test_gateway';
            }

            public function readiness(): array
            {
                return ['status' => 'connected', 'reason' => null];
            }

            public function request(int $amountMinor, string $callbackUrl, string $description, string $paymentUuid): array
            {
                $this->requestCalls++;
                $this->lastCallbackUrl = $callbackUrl;

                return ['accepted' => true, 'authority' => 'AUTH-1', 'redirect_url' => 'https://gateway.test/pay'];
            }

            public function verify(string $authority, int $amountMinor): array
            {
                $this->verifyCalls++;

                return $this->verifyResult;
            }
        };
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);
    }
}
