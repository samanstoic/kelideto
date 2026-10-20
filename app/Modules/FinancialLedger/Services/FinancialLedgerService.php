<?php

namespace App\Modules\FinancialLedger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FinancialLedgerService
{
    public function account(string $number, string $name, string $type, ?string $ownerType = null, ?int $ownerId = null): int
    {
        $account = DB::table('financial_accounts')->where('account_number', $number)->first();
        if ($account) {
            DB::table('financial_accounts')->where('id', $account->id)->update([
                'account_name' => $name,
                'type' => $type,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'updated_at' => now(),
            ]);

            return (int) $account->id;
        }

        DB::table('financial_accounts')->insertOrIgnore([
            'account_number' => $number,
            'account_name' => $name,
            'type' => $type,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('financial_accounts')->where('account_number', $number)->value('id');
    }

    public function wallet(int $userId): object
    {
        $wallet = DB::table('wallets')->where('user_id', $userId)->first();
        if ($wallet) {
            return $wallet;
        }

        $accountId = $this->account(
            'wallet:user:'.$userId,
            'کیف پول کاربر '.$userId,
            'liability',
            'user',
            $userId,
        );
        DB::table('wallets')->insertOrIgnore([
            'user_id' => $userId,
            'account_id' => $accountId,
            'currency' => 'IRR',
            'balance_minor' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('wallets')->where('user_id', $userId)->first();
    }

    public function post(
        string $idempotencyKey,
        string $referenceType,
        ?int $referenceId,
        string $description,
        array $lines,
        ?int $createdByUserId = null,
        ?int $reversalOfTransactionId = null,
    ): object {
        if (strlen($idempotencyKey) > 160 || $lines === []) {
            throw new InvalidArgumentException('A valid idempotency key and journal lines are required.');
        }

        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            $amount = (int) ($line['amount_minor'] ?? 0);
            if ($amount <= 0 || ! in_array($line['entry_type'] ?? null, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Journal amounts must be positive integer minor units.');
            }
            $line['entry_type'] === 'debit' ? $debits += $amount : $credits += $amount;
        }
        if ($debits !== $credits) {
            throw new InvalidArgumentException('Journal debits and credits must balance.');
        }

        $payloadHash = hash('sha256', json_encode([
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR));
        $existing = DB::table('ledger_transactions')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if (! hash_equals((string) $existing->payload_sha256, $payloadHash)) {
                throw new InvalidArgumentException('Ledger idempotency key was reused with different journal data.');
            }

            return $existing;
        }

        $groupId = (string) Str::uuid();
        $transactionId = DB::table('ledger_transactions')->insertGetId([
            'transaction_group_id' => $groupId,
            'idempotency_key' => $idempotencyKey,
            'payload_sha256' => $payloadHash,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reversal_of_transaction_id' => $reversalOfTransactionId,
            'currency' => 'IRR',
            'amount_minor' => $debits,
            'description' => $description,
            'created_by_user_id' => $createdByUserId,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $line) {
            $accountId = (int) $line['account_id'];
            $account = DB::table('financial_accounts')->where('id', $accountId)->lockForUpdate()->first();
            if (! $account || ! $account->is_active || $account->currency !== 'IRR') {
                throw new InvalidArgumentException('Journal account is unavailable or uses an unsupported currency.');
            }
            $amount = (int) $line['amount_minor'];
            $signedDelta = $line['entry_type'] === 'debit' ? $amount : -$amount;
            DB::table('financial_accounts')->where('id', $accountId)->update([
                'balance_minor' => DB::raw('balance_minor + ('.$signedDelta.')'),
                'updated_at' => now(),
            ]);
            DB::table('ledger_entries')->insert([
                'transaction_group_id' => $groupId,
                'ledger_transaction_id' => $transactionId,
                'account_id' => $accountId,
                'payment_id' => $line['payment_id'] ?? null,
                'booking_id' => $line['booking_id'] ?? null,
                'entry_type' => $line['entry_type'],
                'amount_minor' => $amount,
                'amount' => $amount,
                'description' => $description,
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('ledger_transactions')->where('id', $transactionId)->first();
    }
}
