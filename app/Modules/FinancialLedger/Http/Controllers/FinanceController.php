<?php

namespace App\Modules\FinancialLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FinancialLedger\Services\FinancialOperationsService;
use App\Modules\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FinancialOperationsService $finance,
        private readonly PaymentService $payments,
    ) {}

    public function gatewayStatus(Request $request): JsonResponse
    {
        if (! $this->can($request, 'finance.reports.view')) {
            return $this->forbidden();
        }

        return response()->json(['data' => $this->payments->readiness()]);
    }

    public function summary(Request $request): JsonResponse
    {
        if (! $this->can($request, 'finance.reports.view')) {
            return $this->forbidden();
        }
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $validated['from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        return response()->json(['data' => $this->finance->summary($from, $to)]);
    }

    public function settlements(Request $request): JsonResponse
    {
        if (! $this->can($request, 'finance.reports.view')) {
            return $this->forbidden();
        }

        return response()->json([
            'data' => DB::table('technician_settlements')
                ->join('technician_profiles', 'technician_profiles.id', '=', 'technician_settlements.technician_id')
                ->join('users', 'users.id', '=', 'technician_profiles.user_id')
                ->orderByDesc('technician_settlements.id')
                ->limit(100)
                ->get([
                    'technician_settlements.settlement_uuid',
                    'technician_settlements.amount_minor',
                    'technician_settlements.currency',
                    'technician_settlements.status',
                    'technician_settlements.sheba_number',
                    'technician_settlements.account_holder_name',
                    'technician_settlements.paya_reference',
                    'technician_settlements.rejection_reason',
                    'technician_settlements.created_at',
                    'technician_settlements.settled_at',
                    'users.name as technician_name',
                ]),
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        if (! $this->can($request, 'finance.reports.view')) {
            return $this->forbidden();
        }
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = DB::table('ledger_transactions')->orderByDesc('posted_at')->limit(100);
        if (isset($validated['from'])) {
            $query->where('posted_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->where('posted_at', '<=', $validated['to'].' 23:59:59');
        }
        $transactions = $query->get();

        return response()->json([
            'data' => $transactions->map(fn (object $transaction): array => [
                'transaction_group_id' => $transaction->transaction_group_id,
                'idempotency_key' => $transaction->idempotency_key,
                'reference_type' => $transaction->reference_type,
                'reference_id' => $transaction->reference_id,
                'reversal_of_transaction_id' => $transaction->reversal_of_transaction_id,
                'amount_minor' => (int) $transaction->amount_minor,
                'currency' => $transaction->currency,
                'description' => $transaction->description,
                'posted_at' => $transaction->posted_at,
                'entries' => DB::table('ledger_entries')
                    ->join('financial_accounts', 'financial_accounts.id', '=', 'ledger_entries.account_id')
                    ->where('ledger_entries.ledger_transaction_id', $transaction->id)
                    ->orderBy('ledger_entries.id')
                    ->get([
                        'financial_accounts.account_number',
                        'financial_accounts.account_name',
                        'financial_accounts.currency',
                        'ledger_entries.entry_type',
                        'ledger_entries.amount_minor',
                    ]),
            ]),
        ]);
    }

    public function settlementEvents(Request $request, string $settlementUuid): JsonResponse
    {
        if (! $this->can($request, 'finance.reports.view')) {
            return $this->forbidden();
        }
        $settlement = DB::table('technician_settlements')
            ->where('settlement_uuid', $settlementUuid)
            ->first(['id', 'settlement_uuid']);
        if (! $settlement) {
            return response()->json(['error' => 'SETTLEMENT_NOT_FOUND'], 404);
        }

        return response()->json([
            'data' => DB::table('technician_settlement_events')
                ->leftJoin('users', 'users.id', '=', 'technician_settlement_events.changed_by_user_id')
                ->where('settlement_id', $settlement->id)
                ->orderBy('technician_settlement_events.id')
                ->get([
                    'technician_settlement_events.from_status',
                    'technician_settlement_events.to_status',
                    'technician_settlement_events.payout_reference',
                    'technician_settlement_events.reason',
                    'technician_settlement_events.created_at',
                    'users.name as changed_by',
                ]),
        ]);
    }

    public function reviewSettlement(Request $request, string $settlementUuid): JsonResponse
    {
        if (! $this->can($request, 'finance.payout_approve')) {
            return $this->forbidden();
        }
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject,settled'],
            'payout_reference' => ['required_if:decision,settled', 'nullable', 'string', 'max:100'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($request, $validated['password'] ?? null);
        $result = $this->finance->reviewSettlement(
            $settlementUuid,
            (int) $request->user()->id,
            $validated['decision'],
            $validated['payout_reference'] ?? null,
            $validated['reason'] ?? null,
        );
        if (isset($result['error'])) {
            return $this->errorResponse($result['error']);
        }

        return response()->json(['data' => $result]);
    }

    public function refund(Request $request, int $paymentId): JsonResponse
    {
        if (! $this->can($request, 'finance.refund')) {
            return $this->forbidden();
        }
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'password' => ['sometimes', 'nullable', 'string'],
            'confirmation' => ['required', 'in:CONFIRM'],
        ]);
        $this->reauthenticate($request, $validated['password'] ?? null);
        $result = $this->finance->refundToWallet(
            $paymentId,
            (int) $request->user()->id,
            $validated['reason'],
            $validated['idempotency_key'],
        );
        if (isset($result['error'])) {
            return $this->errorResponse($result['error']);
        }

        return response()->json(['data' => $result], ($result['already_created'] ?? false) ? 200 : 201);
    }

    public function reconcile(Request $request): JsonResponse
    {
        if (! $this->can($request, 'finance.reconciliation.run')) {
            return $this->forbidden();
        }
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $result = $this->finance->reconcile(
            $validated['from'],
            $validated['to'],
            (int) $request->user()->id,
        );
        if (isset($result['error'])) {
            return $this->errorResponse($result['error']);
        }

        return response()->json(['data' => $result]);
    }

    private function can(Request $request, string $permission): bool
    {
        return DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.id', $request->user()->id)
            ->where('users.status', 'active')
            ->whereIn('roles.name', ['accountant', 'super_admin'])
            ->where('permissions.name', $permission)
            ->exists();
    }

    private function reauthenticate(Request $request, ?string $password): void
    {
        $user = $request->user();
        if ($password !== null) {
            abort_unless($user?->password && Hash::check($password, $user->password), 422,
                'تأیید مجدد رمز عبور ناموفق بود.');
            return;
        }
        $tokenCreatedAt = $user?->currentAccessToken()?->created_at;
        abort_unless($tokenCreatedAt && now()->subMinutes(5)->lte($tokenCreatedAt), 422,
            'رمز عبور معتبر یا ورود مجدد با رمز یک‌بارمصرف در پنج دقیقهٔ اخیر لازم است.');
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'FORBIDDEN', 'message' => 'مجوز انجام این عملیات مالی را ندارید.'], 403);
    }

    private function errorResponse(string $error): JsonResponse
    {
        $status = match ($error) {
            'SETTLEMENT_NOT_FOUND', 'PAYMENT_NOT_FOUND' => 404,
            'GATEWAY_NOT_CONNECTED' => 503,
            'SETTLEMENT_ALREADY_REVIEWED', 'SETTLEMENT_NOT_APPROVED', 'PAYMENT_ALREADY_REFUNDED',
            'TECHNICIAN_EARNINGS_ALREADY_SPENT', 'IDEMPOTENCY_KEY_REUSED' => 409,
            default => 422,
        };

        return response()->json([
            'error' => $error,
            'message' => match ($error) {
                'TECHNICIAN_EARNINGS_ALREADY_SPENT' => 'بازپرداخت فعلاً ممکن نیست؛ سهم متخصص قبلاً برداشت یا تسویه شده است.',
                'PAYMENT_ALREADY_REFUNDED' => 'این پرداخت قبلاً بازپرداخت شده است.',
                'GATEWAY_NOT_CONNECTED' => 'تطبیق درگاه تا زمان تأیید اتصال درگاه در دسترس نیست.',
                default => 'درخواست مالی در وضعیت فعلی قابل انجام نیست.',
            },
        ], $status);
    }
}
