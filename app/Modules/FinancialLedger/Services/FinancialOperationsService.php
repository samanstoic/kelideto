<?php

namespace App\Modules\FinancialLedger\Services;

use App\Modules\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FinancialOperationsService
{
    private const MIN_SETTLEMENT_MINOR = 100000;

    private const MAX_SETTLEMENT_MINOR = 500000000;

    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    public function wallet(int $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $wallet = $this->ledger->wallet($userId);

            return [
                'currency' => 'IRR',
                'unit' => 'rial',
                'balance_minor' => (int) $wallet->balance_minor,
                'status' => $wallet->status,
                'transactions' => DB::table('wallet_transactions')
                    ->where('wallet_id', $wallet->id)
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get([
                        'type',
                        'amount_minor',
                        'currency',
                        'balance_after_minor',
                        'description',
                        'created_at',
                    ]),
            ];
        });
    }

    public function requestSettlement(
        int $userId,
        int $technicianId,
        int $amountMinor,
        string $sheba,
        string $accountHolder,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use (
            $userId,
            $technicianId,
            $amountMinor,
            $sheba,
            $accountHolder,
            $idempotencyKey,
        ): array {
            $profile = DB::table('technician_profiles')->where('id', $technicianId)->lockForUpdate()->first();
            if (! $profile || (int) $profile->user_id !== $userId || $profile->status !== 'verified') {
                return ['error' => 'VERIFIED_TECHNICIAN_REQUIRED'];
            }
            $existing = DB::table('technician_settlements')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->technician_id !== $technicianId ||
                    (int) $existing->amount_minor !== $amountMinor ||
                    strtoupper((string) $existing->sheba_number) !== strtoupper($sheba) ||
                    $existing->account_holder_name !== $accountHolder) {
                    return ['error' => 'IDEMPOTENCY_KEY_REUSED'];
                }

                return ['settlement_uuid' => $existing->settlement_uuid, 'status' => $existing->status, 'already_created' => true];
            }
            if ($amountMinor < self::MIN_SETTLEMENT_MINOR || $amountMinor > self::MAX_SETTLEMENT_MINOR) {
                return ['error' => 'SETTLEMENT_AMOUNT_OUT_OF_RANGE'];
            }
            $dailyTotal = (int) DB::table('technician_settlements')
                ->where('technician_id', $technicianId)
                ->where('created_at', '>=', now()->startOfDay())
                ->whereNotIn('status', ['rejected', 'cancelled'])
                ->sum('amount_minor');
            if ($dailyTotal + $amountMinor > self::MAX_SETTLEMENT_MINOR) {
                return ['error' => 'DAILY_SETTLEMENT_LIMIT_EXCEEDED'];
            }
            $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $wallet || $wallet->status !== 'active' || (int) $wallet->balance_minor < $amountMinor) {
                return ['error' => 'INSUFFICIENT_WALLET_BALANCE'];
            }

            $settlementId = DB::table('technician_settlements')->insertGetId([
                'settlement_uuid' => (string) Str::uuid(),
                'technician_id' => $technicianId,
                'amount' => $amountMinor,
                'amount_minor' => $amountMinor,
                'currency' => 'IRR',
                'sheba_number' => strtoupper($sheba),
                'account_holder_name' => $accountHolder,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $payout = $this->ledger->account('liability:technician-payouts', 'تسویه‌های متخصصان در انتظار پرداخت', 'liability');
            $reservation = $this->ledger->post(
                'settlement:'.$idempotencyKey.':reserve',
                'technician_settlement',
                $settlementId,
                'رزرو مانده برای درخواست تسویه',
                [
                    ['account_id' => $wallet->account_id, 'entry_type' => 'debit', 'amount_minor' => $amountMinor],
                    ['account_id' => $payout, 'entry_type' => 'credit', 'amount_minor' => $amountMinor],
                ],
                $userId,
            );
            $balanceAfter = (int) $wallet->balance_minor - $amountMinor;
            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance_minor' => $balanceAfter,
                'updated_at' => now(),
            ]);
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'ledger_transaction_id' => $reservation->id,
                'idempotency_key' => 'settlement:'.$idempotencyKey.':reserve',
                'type' => 'payout_reserved',
                'amount_minor' => $amountMinor,
                'currency' => 'IRR',
                'balance_after_minor' => $balanceAfter,
                'description' => 'رزرو وجه برای درخواست تسویه',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('technician_settlements')->where('id', $settlementId)->update([
                'ledger_transaction_id' => $reservation->id,
                'updated_at' => now(),
            ]);
            $this->recordSettlementEvent($settlementId, null, 'pending', $userId);

            return [
                'settlement_uuid' => DB::table('technician_settlements')->where('id', $settlementId)->value('settlement_uuid'),
                'status' => 'pending',
                'amount_minor' => $amountMinor,
                'currency' => 'IRR',
                'already_created' => false,
            ];
        }, 3);
    }

    public function reviewSettlement(string $settlementUuid, int $actorId, string $decision, ?string $payoutReference, ?string $reason): array
    {
        return DB::transaction(function () use ($settlementUuid, $actorId, $decision, $payoutReference, $reason): array {
            $settlement = DB::table('technician_settlements')->where('settlement_uuid', $settlementUuid)->lockForUpdate()->first();
            if (! $settlement) {
                return ['error' => 'SETTLEMENT_NOT_FOUND'];
            }
            if ($decision === 'settled' && $settlement->status !== 'approved') {
                return ['error' => 'SETTLEMENT_NOT_APPROVED'];
            }
            if ($decision !== 'settled' && $settlement->status !== 'pending') {
                return ['error' => 'SETTLEMENT_ALREADY_REVIEWED'];
            }
            $now = now();
            if ($decision === 'approve') {
                DB::table('technician_settlements')->where('id', $settlement->id)->update([
                    'status' => 'approved',
                    'approved_by_user_id' => $actorId,
                    'updated_at' => $now,
                ]);
                $this->recordSettlementEvent((int) $settlement->id, 'pending', 'approved', $actorId);

                return ['settlement_uuid' => $settlementUuid, 'status' => 'approved'];
            }
            if ($decision === 'settled') {
                if (! $payoutReference) {
                    return ['error' => 'PAYOUT_REFERENCE_REQUIRED'];
                }
                DB::table('technician_settlements')->where('id', $settlement->id)->update([
                    'status' => 'settled',
                    'paya_reference' => $payoutReference,
                    'settled_by_user_id' => $actorId,
                    'settled_at' => $now,
                    'updated_at' => $now,
                ]);
                $payout = $this->ledger->account('liability:technician-payouts', 'تسویه‌های متخصصان در انتظار پرداخت', 'liability');
                $bank = $this->ledger->account('asset:bank:technician-payouts', 'وجوه بانکی پرداخت تسویه متخصصان', 'asset');
                $posted = $this->ledger->post(
                    'settlement:'.$settlement->idempotency_key.':paid',
                    'technician_settlement',
                    (int) $settlement->id,
                    'ثبت پرداخت تسویه با مرجع '.$payoutReference,
                    [
                        ['account_id' => $payout, 'entry_type' => 'debit', 'amount_minor' => (int) $settlement->amount_minor],
                        ['account_id' => $bank, 'entry_type' => 'credit', 'amount_minor' => (int) $settlement->amount_minor],
                    ],
                    $actorId,
                );
                DB::table('technician_settlements')->where('id', $settlement->id)->update([
                    'payout_ledger_transaction_id' => $posted->id,
                ]);
                $this->recordSettlementEvent((int) $settlement->id, 'approved', 'settled', $actorId, $payoutReference);

                return ['settlement_uuid' => $settlementUuid, 'status' => 'settled'];
            }
            if ($decision !== 'reject') {
                return ['error' => 'INVALID_SETTLEMENT_DECISION'];
            }

            $technicianUserId = DB::table('technician_profiles')->where('id', $settlement->technician_id)->value('user_id');
            $wallet = DB::table('wallets')->where('user_id', $technicianUserId)->lockForUpdate()->first();
            $payout = $this->ledger->account('liability:technician-payouts', 'تسویه‌های متخصصان در انتظار پرداخت', 'liability');
            $release = $this->ledger->post(
                'settlement:'.$settlement->idempotency_key.':release',
                'technician_settlement',
                (int) $settlement->id,
                'آزادسازی وجه درخواست تسویه ردشده',
                [
                    ['account_id' => $payout, 'entry_type' => 'debit', 'amount_minor' => (int) $settlement->amount_minor],
                    ['account_id' => $wallet->account_id, 'entry_type' => 'credit', 'amount_minor' => (int) $settlement->amount_minor],
                ],
                $actorId,
            );
            $balanceAfter = (int) $wallet->balance_minor + (int) $settlement->amount_minor;
            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance_minor' => $balanceAfter,
                'updated_at' => $now,
            ]);
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'ledger_transaction_id' => $release->id,
                'idempotency_key' => 'settlement:'.$settlement->idempotency_key.':release',
                'type' => 'payout_released',
                'amount_minor' => (int) $settlement->amount_minor,
                'currency' => 'IRR',
                'balance_after_minor' => $balanceAfter,
                'description' => 'بازگشت وجه درخواست تسویه ردشده',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('technician_settlements')->where('id', $settlement->id)->update([
                'status' => 'rejected',
                'approved_by_user_id' => $actorId,
                'rejection_reason' => $reason,
                'updated_at' => $now,
            ]);
            $this->recordSettlementEvent((int) $settlement->id, 'pending', 'rejected', $actorId, reason: $reason);

            return ['settlement_uuid' => $settlementUuid, 'status' => 'rejected'];
        }, 3);
    }

    public function refundToWallet(int $paymentId, int $actorId, string $reason, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($paymentId, $actorId, $reason, $idempotencyKey): array {
            $existing = DB::table('payment_refunds')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->payment_id !== $paymentId) {
                    return ['error' => 'IDEMPOTENCY_KEY_REUSED'];
                }

                return ['refund_uuid' => $existing->refund_uuid, 'status' => $existing->status, 'already_created' => true];
            }
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment || $payment->status !== 'paid') {
                return ['error' => 'PAYMENT_NOT_REFUNDABLE'];
            }
            if (DB::table('payment_refunds')->where('payment_id', $paymentId)->exists()) {
                return ['error' => 'PAYMENT_ALREADY_REFUNDED'];
            }
            $allocation = DB::table('ledger_transactions')
                ->where('idempotency_key', 'payment:'.$payment->payment_uuid.':allocation')
                ->first();
            if (! $allocation) {
                return ['error' => 'PAYMENT_LEDGER_MISSING'];
            }
            $booking = DB::table('bookings')->where('id', $payment->booking_id)->first();
            $technicianUserId = DB::table('technician_profiles')->where('id', $booking->technician_id ?? 0)->value('user_id');
            $technicianWallet = $technicianUserId ? DB::table('wallets')->where('user_id', $technicianUserId)->lockForUpdate()->first() : null;
            $technicianAmount = (int) DB::table('ledger_entries')
                ->where('ledger_transaction_id', $allocation->id)
                ->where('entry_type', 'credit')
                ->whereIn('account_id', DB::table('wallets')->select('account_id'))
                ->sum('amount_minor');
            if ($technicianAmount > 0 && (! $technicianWallet || (int) $technicianWallet->balance_minor < $technicianAmount)) {
                return ['error' => 'TECHNICIAN_EARNINGS_ALREADY_SPENT'];
            }

            $refundId = DB::table('payment_refunds')->insertGetId([
                'refund_uuid' => (string) Str::uuid(),
                'payment_id' => $paymentId,
                'idempotency_key' => $idempotencyKey,
                'amount_minor' => (int) $payment->amount_minor,
                'currency' => 'IRR',
                'status' => 'processing',
                'reason' => $reason,
                'created_by_user_id' => $actorId,
                'refunded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $originalLines = DB::table('ledger_entries')->where('ledger_transaction_id', $allocation->id)->get();
            $reverseLines = [];
            foreach ($originalLines as $line) {
                $reverseLines[] = [
                    'account_id' => $line->account_id,
                    'entry_type' => $line->entry_type === 'debit' ? 'credit' : 'debit',
                    'amount_minor' => (int) $line->amount_minor,
                    'payment_id' => $paymentId,
                    'booking_id' => $booking->id,
                ];
            }
            $reversal = $this->ledger->post(
                'refund:'.$idempotencyKey.':reversal',
                'payment_refund',
                $refundId,
                'معکوس‌سازی تخصیص پرداخت '.$payment->payment_uuid,
                $reverseLines,
                $actorId,
                (int) $allocation->id,
            );
            if ($technicianAmount > 0) {
                $balanceAfter = (int) $technicianWallet->balance_minor - $technicianAmount;
                DB::table('wallets')->where('id', $technicianWallet->id)->update([
                    'balance_minor' => $balanceAfter,
                    'updated_at' => now(),
                ]);
                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $technicianWallet->id,
                    'ledger_transaction_id' => $reversal->id,
                    'idempotency_key' => 'refund:'.$idempotencyKey.':technician-debit',
                    'type' => 'earning_reversed',
                    'amount_minor' => $technicianAmount,
                    'currency' => 'IRR',
                    'balance_after_minor' => $balanceAfter,
                    'description' => 'برگشت سهم متخصص بابت بازپرداخت سفارش',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $customerWallet = $this->ledger->wallet((int) $payment->user_id);
            $escrow = $this->ledger->account('liability:booking-escrow', 'وجوه امانی سفارش', 'liability');
            $reclass = $this->ledger->post(
                'refund:'.$idempotencyKey.':wallet-credit',
                'payment_refund',
                $refundId,
                'ثبت اعتبار بازپرداخت در کیف پول مشتری',
                [
                    ['account_id' => $escrow, 'entry_type' => 'debit', 'amount_minor' => (int) $payment->amount_minor, 'payment_id' => $paymentId, 'booking_id' => $booking->id],
                    ['account_id' => $customerWallet->account_id, 'entry_type' => 'credit', 'amount_minor' => (int) $payment->amount_minor, 'payment_id' => $paymentId, 'booking_id' => $booking->id],
                ],
                $actorId,
            );
            $customerBalance = (int) $customerWallet->balance_minor + (int) $payment->amount_minor;
            DB::table('wallets')->where('id', $customerWallet->id)->update([
                'balance_minor' => $customerBalance,
                'updated_at' => now(),
            ]);
            $customerTransactionId = DB::table('wallet_transactions')->insertGetId([
                'wallet_id' => $customerWallet->id,
                'ledger_transaction_id' => $reclass->id,
                'idempotency_key' => 'refund:'.$idempotencyKey.':customer-credit',
                'type' => 'refund',
                'amount_minor' => (int) $payment->amount_minor,
                'currency' => 'IRR',
                'balance_after_minor' => $customerBalance,
                'description' => 'بازپرداخت کیف پول سفارش '.$booking->booking_code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('payment_refunds')->where('id', $refundId)->update([
                'wallet_transaction_id' => $customerTransactionId,
                'status' => 'wallet_credited',
                'updated_at' => now(),
            ]);
            DB::table('payments')->where('id', $paymentId)->update([
                'status' => 'refunded',
                'updated_at' => now(),
            ]);

            return [
                'refund_uuid' => DB::table('payment_refunds')->where('id', $refundId)->value('refund_uuid'),
                'status' => 'wallet_credited',
                'amount_minor' => (int) $payment->amount_minor,
                'currency' => 'IRR',
                'destination' => 'customer_wallet',
                'already_created' => false,
            ];
        }, 3);
    }

    public function summary(string $from, string $to): array
    {
        $revenue = DB::table('ledger_entries')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'ledger_entries.account_id')
            ->where('financial_accounts.account_number', 'revenue:platform-commission')
            ->whereBetween('ledger_entries.posted_at', [$from, $to.' 23:59:59'])
            ->selectRaw("COALESCE(SUM(CASE WHEN ledger_entries.entry_type = 'credit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END), 0) as amount")
            ->value('amount');

        return [
            'currency' => 'IRR',
            'unit' => 'rial',
            'period' => ['from' => $from, 'to' => $to],
            'paid_amount_minor' => (int) DB::table('payments')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to.' 23:59:59'])->sum('amount_minor'),
            'platform_commission_minor' => (int) $revenue,
            'technician_earnings_minor' => (int) DB::table('wallet_transactions')
                ->where('type', 'earning')
                ->whereBetween('created_at', [$from, $to.' 23:59:59'])
                ->sum('amount_minor'),
            'technician_earnings_reversed_minor' => (int) DB::table('wallet_transactions')
                ->where('type', 'earning_reversed')
                ->whereBetween('created_at', [$from, $to.' 23:59:59'])
                ->sum('amount_minor'),
            'refunds_minor' => (int) DB::table('payment_refunds')
                ->where('status', 'wallet_credited')
                ->whereBetween('refunded_at', [$from, $to.' 23:59:59'])
                ->sum('amount_minor'),
            'settlements_minor' => (int) DB::table('technician_settlements')
                ->where('status', 'settled')
                ->whereBetween('settled_at', [$from, $to.' 23:59:59'])
                ->sum('amount_minor'),
        ];
    }

    public function reconcile(string $from, string $to, int $actorId): array
    {
        if ($this->gateway->readiness()['status'] !== 'connected') {
            return ['error' => 'GATEWAY_NOT_CONNECTED'];
        }
        $payments = DB::table('payments')
            ->where('gateway_name', $this->gateway->name())
            ->whereIn('status', ['pending', 'paid', 'refunded'])
            ->whereBetween('created_at', [$from, $to.' 23:59:59'])
            ->orderBy('id')
            ->limit(1000)
            ->get();
        $mismatches = [];
        $matched = 0;
        foreach ($payments as $payment) {
            if (! $payment->provider_authority) {
                $mismatches[] = ['payment_uuid' => $payment->payment_uuid, 'reason' => 'MISSING_PROVIDER_AUTHORITY'];

                continue;
            }
            try {
                $verification = $this->gateway->verify((string) $payment->provider_authority, (int) $payment->amount_minor);
            } catch (ConnectionException|\RuntimeException $exception) {
                Log::warning('Financial reconciliation could not contact payment gateway.', [
                    'payment_uuid' => $payment->payment_uuid,
                    'exception' => $exception::class,
                ]);
                $mismatches[] = ['payment_uuid' => $payment->payment_uuid, 'reason' => 'PROVIDER_UNAVAILABLE'];

                continue;
            }
            $valid = ($verification['verified'] ?? false) &&
                (! in_array($payment->status, ['paid', 'refunded'], true) ||
                    hash_equals((string) $payment->transaction_reference, (string) $verification['reference']));
            if (! $valid) {
                $mismatches[] = ['payment_uuid' => $payment->payment_uuid, 'reason' => 'PROVIDER_STATUS_OR_REFERENCE_MISMATCH'];

                continue;
            }
            $paymentJournals = DB::table('ledger_transactions')
                ->where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->get(['id']);
            $journalBalanced = $paymentJournals->count() === 2;
            foreach ($paymentJournals as $journal) {
                $journalBalance = DB::table('ledger_entries')
                    ->where('ledger_transaction_id', $journal->id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'debit' THEN amount_minor ELSE -amount_minor END), 0) as balance")
                    ->value('balance');
                $journalBalanced = $journalBalanced && (int) $journalBalance === 0;
            }
            if (in_array($payment->status, ['paid', 'refunded'], true) && ! $journalBalanced) {
                $mismatches[] = ['payment_uuid' => $payment->payment_uuid, 'reason' => 'LEDGER_UNBALANCED'];

                continue;
            }
            $matched++;
        }

        $accountMismatches = DB::table('financial_accounts')
            ->leftJoin('ledger_entries', 'ledger_entries.account_id', '=', 'financial_accounts.id')
            ->select(
                'financial_accounts.id',
                'financial_accounts.account_number',
                'financial_accounts.balance_minor',
            )
            ->selectRaw("COALESCE(SUM(CASE WHEN ledger_entries.entry_type = 'debit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END), 0) as ledger_balance")
            ->groupBy('financial_accounts.id', 'financial_accounts.account_number', 'financial_accounts.balance_minor')
            ->get()
            ->filter(fn (object $account): bool => (int) $account->balance_minor !== (int) $account->ledger_balance)
            ->map(fn (object $account): array => [
                'reason' => 'ACCOUNT_BALANCE_MISMATCH',
                'account_number' => $account->account_number,
            ])
            ->values()
            ->all();
        array_push($mismatches, ...$accountMismatches);
        $wallets = DB::table('wallets')->get(['id', 'user_id', 'account_id', 'balance_minor']);
        foreach ($wallets as $wallet) {
            $ledgerBalance = DB::table('ledger_entries')
                ->where('account_id', $wallet->account_id)
                ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'credit' THEN amount_minor ELSE -amount_minor END), 0) as balance")
                ->value('balance');
            if ((int) $wallet->balance_minor !== (int) $ledgerBalance) {
                $mismatches[] = ['reason' => 'WALLET_BALANCE_MISMATCH', 'wallet_user_id' => (int) $wallet->user_id];
            }
        }

        $reportId = DB::table('finance_reconciliation_reports')->insertGetId([
            'report_uuid' => (string) Str::uuid(),
            'gateway_name' => $this->gateway->name(),
            'period_start' => $from,
            'period_end' => $to,
            'payments_checked' => $payments->count(),
            'matched_count' => $matched,
            'mismatch_count' => count($mismatches),
            'mismatches' => json_encode($mismatches, JSON_THROW_ON_ERROR),
            'created_by_user_id' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'report_uuid' => DB::table('finance_reconciliation_reports')->where('id', $reportId)->value('report_uuid'),
            'gateway' => $this->gateway->name(),
            'payments_checked' => $payments->count(),
            'matched_count' => $matched,
            'mismatch_count' => count($mismatches),
            'mismatches' => $mismatches,
        ];
    }

    private function recordSettlementEvent(
        int $settlementId,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorId,
        ?string $payoutReference = null,
        ?string $reason = null,
    ): void {
        DB::table('technician_settlement_events')->insert([
            'settlement_id' => $settlementId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by_user_id' => $actorId,
            'payout_reference' => $payoutReference,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
