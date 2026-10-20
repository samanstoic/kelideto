<?php

namespace App\Modules\Payment\Services;

use App\Modules\Booking\Services\BookingLifecycleService;
use App\Modules\FinancialLedger\Services\FinancialLedgerService;
use App\Modules\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly FinancialLedgerService $ledger,
        private readonly BookingLifecycleService $bookingLifecycle,
    ) {}

    public function readiness(): array
    {
        return [
            'gateway' => $this->gateway->name(),
            ...$this->gateway->readiness(),
            'currency' => 'IRR',
            'unit' => 'rial',
            'minor_unit_scale' => 0,
        ];
    }

    public function initiate(int $userId, int $bookingId, string $idempotencyKey, string $callbackUrl): array
    {
        if ($this->gateway->readiness()['status'] !== 'connected') {
            return ['error' => 'GATEWAY_NOT_CONNECTED'];
        }

        $payment = DB::transaction(function () use ($userId, $bookingId, $idempotencyKey): array {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            $customer = DB::table('customer_profiles')->where('id', $booking->customer_id ?? 0)->value('user_id');
            if (! $booking || (int) $customer !== $userId) {
                return ['error' => 'BOOKING_NOT_FOUND'];
            }
            $existing = DB::table('payments')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->booking_id !== $bookingId || (int) $existing->user_id !== $userId) {
                    return ['error' => 'IDEMPOTENCY_KEY_REUSED'];
                }
                if ($existing->status === 'paid' || $existing->status === 'refunded') {
                    return ['payment' => $existing, 'already_created' => true];
                }
                if ($existing->status !== 'pending') {
                    return ['error' => 'PAYMENT_ATTEMPT_CLOSED'];
                }
                if (! $existing->provider_authority) {
                    return ['error' => 'PAYMENT_REQUEST_IN_PROGRESS'];
                }

                return ['payment' => $existing, 'already_created' => true];
            }
            if ($booking->status !== 'payment_pending') {
                return ['error' => 'BOOKING_NOT_PAYABLE'];
            }
            $successful = DB::table('payments')->where('booking_id', $bookingId)->where('status', 'paid')->exists();
            if ($successful) {
                return ['error' => 'BOOKING_ALREADY_PAID'];
            }
            $pending = DB::table('payments')->where('booking_id', $bookingId)->where('status', 'pending')->first();
            if ($pending) {
                return ['error' => 'PAYMENT_ALREADY_PENDING', 'payment' => $pending, 'already_created' => true];
            }

            $amount = self::legacyAmountToRials((string) ($booking->final_price ?? $booking->estimated_price));
            if ($amount <= 0) {
                return ['error' => 'INVALID_PAYMENT_AMOUNT'];
            }
            $id = DB::table('payments')->insertGetId([
                'payment_uuid' => (string) Str::uuid(),
                'booking_id' => $bookingId,
                'user_id' => $userId,
                'amount' => $amount,
                'amount_minor' => $amount,
                'currency' => 'IRR',
                'gateway_name' => $this->gateway->name(),
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['payment' => DB::table('payments')->where('id', $id)->first(), 'already_created' => false];
        }, 3);

        if (isset($payment['error'])) {
            return $payment;
        }
        $row = $payment['payment'];
        if (in_array($row->status, ['paid', 'refunded'], true)) {
            return $this->paymentResponse($row, true);
        }
        if ($row->provider_authority && $payment['already_created']) {
            return $this->paymentResponse($row, true);
        }

        try {
            $requested = $this->gateway->request(
                (int) $row->amount_minor,
                str_replace('{paymentUuid}', (string) $row->payment_uuid, $callbackUrl),
                'پرداخت سفارش '.substr((string) $row->payment_uuid, 0, 8),
                (string) $row->payment_uuid,
            );
        } catch (ConnectionException|RuntimeException $exception) {
            DB::transaction(function () use ($row, $exception): void {
                $this->recordEvent((int) $row->id, 'request_failed', 'request:'.$row->payment_uuid, [
                    'exception' => class_basename($exception),
                ]);
                DB::table('payments')->where('id', $row->id)->where('status', 'pending')->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            });
            Log::warning('Payment gateway request failed.', [
                'payment_uuid' => $row->payment_uuid,
                'exception' => $exception::class,
            ]);

            return ['error' => 'GATEWAY_REQUEST_FAILED'];
        }
        if (! ($requested['accepted'] ?? false)) {
            DB::transaction(function () use ($row, $requested): void {
                $this->recordEvent((int) $row->id, 'request_rejected', 'request:'.$row->payment_uuid, [
                    'code' => $requested['code'] ?? null,
                    'error' => $requested['error'] ?? null,
                ]);
                DB::table('payments')->where('id', $row->id)->where('status', 'pending')->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            });

            return ['error' => $requested['error'] ?? 'GATEWAY_REQUEST_REJECTED'];
        }
        if (! is_string($requested['authority'] ?? null) || $requested['authority'] === '' ||
            ! is_string($requested['redirect_url'] ?? null) || $requested['redirect_url'] === '') {
            DB::transaction(function () use ($row): void {
                $this->recordEvent((int) $row->id, 'request_rejected', 'malformed-request:'.$row->payment_uuid, [
                    'reason' => 'INVALID_GATEWAY_RESPONSE',
                ]);
                DB::table('payments')->where('id', $row->id)->where('status', 'pending')->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            });

            return ['error' => 'INVALID_GATEWAY_RESPONSE'];
        }

        DB::transaction(function () use ($row, $requested): void {
            DB::table('payments')->where('id', $row->id)->update([
                'provider_authority' => $requested['authority'],
                'redirect_url' => $requested['redirect_url'],
                'gateway_payload' => json_encode(['request_code' => $requested['code'] ?? null], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->recordEvent((int) $row->id, 'redirect_created', 'redirect:'.$row->payment_uuid, []);
        });

        return [
            'payment_uuid' => $row->payment_uuid,
            'amount_minor' => (int) $row->amount_minor,
            'currency' => 'IRR',
            'redirect_url' => $requested['redirect_url'],
            'status' => 'pending',
            'already_created' => false,
        ];
    }

    public function callback(string $paymentUuid, ?string $authority, ?string $providerStatus, ?string $sourceIp): array
    {
        $payment = DB::table('payments')->where('payment_uuid', $paymentUuid)->first();
        if (! $payment) {
            return ['error' => 'PAYMENT_NOT_FOUND'];
        }
        $eventKey = 'callback:'.hash('sha256', $paymentUuid.'|'.($authority ?? '').'|'.($providerStatus ?? ''));
        if (! $authority || strlen($authority) > 100 ||
            ! hash_equals((string) $payment->provider_authority, $authority)) {
            DB::transaction(fn () => $this->recordEvent((int) $payment->id, 'callback_rejected', $eventKey, [
                'status' => $providerStatus,
                'source_ip' => $sourceIp,
            ]));

            return ['error' => 'INVALID_GATEWAY_CALLBACK'];
        }
        if ($payment->status === 'paid') {
            return ['payment_uuid' => $paymentUuid, 'status' => 'paid', 'already_processed' => true];
        }
        if ($payment->status !== 'pending') {
            return ['error' => 'PAYMENT_NOT_PENDING'];
        }

        DB::transaction(fn () => $this->recordEvent((int) $payment->id, 'callback_received', $eventKey, [
            'status' => $providerStatus,
            'source_ip' => $sourceIp,
        ]));

        try {
            $verification = $this->gateway->verify($authority, (int) $payment->amount_minor);
        } catch (ConnectionException|RuntimeException $exception) {
            DB::transaction(function () use ($payment, $eventKey, $exception): void {
                $this->recordEvent((int) $payment->id, 'verification_error', 'verification-error:'.$eventKey, [
                    'exception' => class_basename($exception),
                ]);
            });
            Log::warning('Payment verification request failed.', [
                'payment_uuid' => $paymentUuid,
                'exception' => $exception::class,
            ]);

            return ['error' => 'GATEWAY_VERIFICATION_UNAVAILABLE'];
        }
        if (! ($verification['verified'] ?? false) ||
            ! is_scalar($verification['reference'] ?? null) ||
            trim((string) $verification['reference']) === '') {
            DB::transaction(function () use ($payment, $eventKey, $verification): void {
                $this->recordEvent((int) $payment->id, 'verification_rejected', 'verification-rejected:'.$eventKey, [
                    'code' => $verification['code'] ?? null,
                    'reason' => ($verification['verified'] ?? false) ? 'INVALID_PROVIDER_REFERENCE' : null,
                ]);
                DB::table('payments')->where('id', $payment->id)->where('status', 'pending')->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            });

            return ['error' => 'PAYMENT_NOT_VERIFIED'];
        }

        return DB::transaction(function () use ($payment, $verification, $eventKey): array {
            $locked = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->first();
            if ($locked->status === 'paid') {
                return ['payment_uuid' => $locked->payment_uuid, 'status' => 'paid', 'already_processed' => true];
            }
            if ($locked->status !== 'pending') {
                return ['error' => 'PAYMENT_NOT_PENDING'];
            }
            if (DB::table('payments')->where('transaction_reference', $verification['reference'])->where('id', '!=', $locked->id)->exists()) {
                $this->recordEvent((int) $locked->id, 'verification_rejected', 'duplicate-reference:'.$eventKey, [
                    'reason' => 'PROVIDER_REFERENCE_REUSED',
                ]);

                return ['error' => 'DUPLICATE_PROVIDER_REFERENCE'];
            }
            $booking = DB::table('bookings')->where('id', $locked->booking_id)->lockForUpdate()->first();
            if (! $booking || $booking->status !== 'payment_pending') {
                return ['error' => 'BOOKING_NOT_PAYABLE'];
            }
            $technicianUserId = DB::table('technician_profiles')->where('id', $booking->technician_id ?? 0)->value('user_id');
            if (! $technicianUserId) {
                return ['error' => 'BOOKING_TECHNICIAN_MISSING'];
            }

            $amount = (int) $locked->amount_minor;
            $fee = (int) ($booking->platform_commission_amount ?? 0) > 0
                ? self::legacyAmountToRials((string) $booking->platform_commission_amount)
                : self::commission($amount, (string) $booking->platform_commission_rate);
            if ($fee > $amount) {
                return ['error' => 'INVALID_COMMISSION_AMOUNT'];
            }
            DB::table('payments')->where('id', $locked->id)->update([
                'status' => 'paid',
                'transaction_reference' => $verification['reference'],
                'tracking_code' => $verification['reference'],
                'paid_at' => now(),
                'verified_at' => now(),
                'gateway_payload' => json_encode(['verification_code' => $verification['code'] ?? null], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            $cash = $this->ledger->account('asset:gateway:'.$this->gateway->name(), 'وجوه نزد درگاه '.$this->gateway->name(), 'asset');
            $escrow = $this->ledger->account('liability:booking-escrow', 'وجوه امانی سفارش', 'liability');
            $platformRevenue = $this->ledger->account('revenue:platform-commission', 'درآمد کارمزد پلتفرم', 'revenue');
            $wallet = $this->ledger->wallet((int) $technicianUserId);
            $this->ledger->post(
                'payment:'.$locked->payment_uuid.':capture',
                'payment',
                (int) $locked->id,
                'تأیید پرداخت سفارش '.$booking->booking_code,
                [
                    ['account_id' => $cash, 'entry_type' => 'debit', 'amount_minor' => $amount, 'payment_id' => $locked->id, 'booking_id' => $booking->id],
                    ['account_id' => $escrow, 'entry_type' => 'credit', 'amount_minor' => $amount, 'payment_id' => $locked->id, 'booking_id' => $booking->id],
                ],
            );
            $allocationLines = [
                ['account_id' => $escrow, 'entry_type' => 'debit', 'amount_minor' => $amount, 'payment_id' => $locked->id, 'booking_id' => $booking->id],
            ];
            if ($fee > 0) {
                $allocationLines[] = ['account_id' => $platformRevenue, 'entry_type' => 'credit', 'amount_minor' => $fee, 'payment_id' => $locked->id, 'booking_id' => $booking->id];
            }
            if ($amount - $fee > 0) {
                $allocationLines[] = ['account_id' => $wallet->account_id, 'entry_type' => 'credit', 'amount_minor' => $amount - $fee, 'payment_id' => $locked->id, 'booking_id' => $booking->id];
            }
            $allocation = $this->ledger->post(
                'payment:'.$locked->payment_uuid.':allocation',
                'payment',
                (int) $locked->id,
                'تخصیص سهم متخصص و کارمزد پلتفرم',
                $allocationLines,
            );
            DB::table('wallets')->where('id', $wallet->id)->increment('balance_minor', $amount - $fee);
            if ($amount > $fee) {
                $balanceAfter = (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor');
                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $wallet->id,
                    'ledger_transaction_id' => $allocation->id,
                    'idempotency_key' => 'payment:'.$locked->payment_uuid.':credit',
                    'type' => 'earning',
                    'amount_minor' => $amount - $fee,
                    'currency' => 'IRR',
                    'balance_after_minor' => $balanceAfter,
                    'description' => 'سهم متخصص از سفارش '.$booking->booking_code,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('gateway_health_checks')->insert([
                'gateway_name' => $this->gateway->name(),
                'check_type' => 'verified_payment',
                'provider_reference_hash' => hash('sha256', (string) $verification['reference']),
                'passed' => true,
                'result_code' => (string) ($verification['code'] ?? 'verified'),
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordEvent((int) $locked->id, 'verified', 'verified:'.$eventKey, [
                'reference_hash' => hash('sha256', (string) $verification['reference']),
                'amount_minor' => $amount,
            ]);

            $transition = $this->bookingLifecycle->transition(
                (int) $booking->id,
                'completed',
                null,
                'پرداخت با استعلام رسمی درگاه تأیید شد.',
            );
            if (isset($transition['error'])) {
                throw new RuntimeException('Could not complete the paid booking: '.$transition['error']);
            }

            return ['payment_uuid' => $locked->payment_uuid, 'status' => 'paid', 'already_processed' => false];
        }, 3);
    }

    public static function legacyAmountToRials(string $amount): int
    {
        if (! preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('Legacy monetary amount is not a valid non-negative decimal.');
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($matches[1]) > strlen($max) ||
            (strlen($matches[1]) === strlen($max) && strcmp($matches[1], $max) > 0) ||
            ((int) $matches[1] === PHP_INT_MAX && (int) ($matches[2][0] ?? '0') >= 5)) {
            throw new RuntimeException('Monetary amount exceeds supported integer range.');
        }
        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return $whole + ((int) $fraction[0] >= 5 ? 1 : 0);
    }

    public static function commission(int $amount, string $rate): int
    {
        if (! preg_match('/^(0|[1-9]\d?)(?:\.(\d{1,2}))?$/', $rate, $matches)) {
            throw new RuntimeException('Commission percentage is invalid.');
        }
        $basisPoints = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($basisPoints > 10000) {
            throw new RuntimeException('Commission percentage cannot exceed 100 percent.');
        }

        $whole = intdiv($amount, 10000) * $basisPoints;
        $remainder = ($amount % 10000) * $basisPoints;

        return $whole + intdiv($remainder + 5000, 10000);
    }

    private function paymentResponse(object $payment, bool $alreadyCreated): array
    {
        return [
            'payment_uuid' => $payment->payment_uuid,
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => 'IRR',
            'redirect_url' => $payment->status === 'pending' ? $payment->redirect_url : null,
            'status' => $payment->status,
            'already_created' => $alreadyCreated,
        ];
    }

    private function recordEvent(int $paymentId, string $type, string $eventKey, array $payload): void
    {
        DB::table('payment_events')->insertOrIgnore([
            'payment_id' => $paymentId,
            'event_type' => $type,
            'event_key' => substr($eventKey, 0, 128),
            'source_ip' => $payload['source_ip'] ?? null,
            'payload' => json_encode(array_diff_key($payload, ['source_ip' => true]), JSON_THROW_ON_ERROR),
            'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
