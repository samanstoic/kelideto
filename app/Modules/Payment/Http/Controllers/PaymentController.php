<?php

namespace App\Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FinancialLedger\Services\FinancialOperationsService;
use App\Modules\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly FinancialOperationsService $finance,
    ) {}

    public function gatewayStatus(): JsonResponse
    {
        return response()->json(['data' => $this->payments->readiness()]);
    }

    public function initiate(Request $request, int $bookingId): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
        ]);
        $callbackUrl = rtrim((string) config('app.url'), '/').'/api/payments/{paymentUuid}/callback';

        $result = $this->payments->initiate(
            (int) $request->user()->id,
            $bookingId,
            $validated['idempotency_key'],
            $callbackUrl,
        );
        if (isset($result['error'])) {
            return $this->errorResponse($result['error']);
        }

        return response()->json(['data' => $result], ($result['already_created'] ?? false) ? 200 : 201);
    }

    public function callback(Request $request, string $paymentUuid): JsonResponse|RedirectResponse
    {
        if (! Str::isUuid($paymentUuid)) {
            return response()->json(['error' => 'PAYMENT_NOT_FOUND'], 404);
        }
        $result = $this->payments->callback(
            $paymentUuid,
            $request->query('Authority'),
            $request->query('Status'),
            $request->ip(),
        );
        if (isset($result['error'])) {
            if (! $request->expectsJson()) {
                return $this->paymentReturnRedirect($paymentUuid, 'unconfirmed');
            }

            return $this->errorResponse($result['error']);
        }
        if (! $request->expectsJson()) {
            return $this->paymentReturnRedirect($paymentUuid, 'returned');
        }

        return response()->json([
            'data' => [
                ...$result,
                'message' => 'پرداخت با استعلام سمت سرور تأیید شد.',
            ],
        ]);
    }

    private function paymentReturnRedirect(string $paymentUuid, string $result): RedirectResponse
    {
        $bookingCode = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('payments.payment_uuid', $paymentUuid)
            ->value('bookings.booking_code');
        $query = http_build_query([
            'frontend' => 'vanilla',
            'code' => $bookingCode,
            'payment_uuid' => $paymentUuid,
            'payment_result' => $result,
        ]);

        return redirect()->to(rtrim((string) config('app.url'), '/').'/orders?'.$query);
    }

    public function show(Request $request, string $paymentUuid): JsonResponse
    {
        $payment = DB::table('payments')
            ->where('payment_uuid', $paymentUuid)
            ->where('user_id', $request->user()->id)
            ->first([
                'payment_uuid',
                'booking_id',
                'amount_minor',
                'currency',
                'status',
                'transaction_reference',
                'paid_at',
                'verified_at',
            ]);
        if (! $payment) {
            return response()->json(['error' => 'PAYMENT_NOT_FOUND'], 404);
        }

        return response()->json(['data' => $payment]);
    }

    public function wallet(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->finance->wallet((int) $request->user()->id)]);
    }

    public function requestSettlement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'sheba_number' => ['required', 'string', 'regex:/^IR\d{24}$/i'],
            'account_holder_name' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
        ]);
        $technicianId = DB::table('technician_profiles')->where('user_id', $request->user()->id)->value('id');
        if (! $technicianId) {
            return $this->errorResponse('VERIFIED_TECHNICIAN_REQUIRED');
        }
        $result = $this->finance->requestSettlement(
            (int) $request->user()->id,
            (int) $technicianId,
            (int) $validated['amount_minor'],
            $validated['sheba_number'],
            $validated['account_holder_name'],
            $validated['idempotency_key'],
        );
        if (isset($result['error'])) {
            return $this->errorResponse($result['error']);
        }

        return response()->json(['data' => $result], ($result['already_created'] ?? false) ? 200 : 201);
    }

    private function errorResponse(string $error): JsonResponse
    {
        $status = match ($error) {
            'BOOKING_NOT_FOUND', 'PAYMENT_NOT_FOUND', 'SETTLEMENT_NOT_FOUND' => 404,
            'GATEWAY_NOT_CONNECTED', 'GATEWAY_NOT_VERIFIED', 'GATEWAY_REQUEST_FAILED', 'GATEWAY_VERIFICATION_UNAVAILABLE' => 503,
            'INVALID_GATEWAY_CALLBACK', 'PAYMENT_NOT_VERIFIED' => 422,
            'IDEMPOTENCY_KEY_REUSED', 'BOOKING_ALREADY_PAID', 'PAYMENT_ALREADY_PENDING', 'BOOKING_NOT_PAYABLE',
            'PAYMENT_NOT_PENDING', 'DUPLICATE_PROVIDER_REFERENCE', 'PAYMENT_ATTEMPT_CLOSED',
            'PAYMENT_REQUEST_IN_PROGRESS' => 409,
            'VERIFIED_TECHNICIAN_REQUIRED' => 403,
            default => 422,
        };
        $messages = [
            'GATEWAY_NOT_CONNECTED' => 'درگاه پرداخت تا زمان تأیید اتصال فعال نمی‌شود.',
            'GATEWAY_NOT_VERIFIED' => 'درگاه هنوز با تراکنش معتبر آزموده نشده است.',
            'GATEWAY_VERIFICATION_UNAVAILABLE' => 'استعلام امن درگاه موقتاً در دسترس نیست؛ پرداخت تأییدنشده باقی ماند.',
            'PAYMENT_NOT_VERIFIED' => 'درگاه پرداخت را تأیید نکرد؛ بازگشت مرورگر به‌تنهایی پرداخت محسوب نمی‌شود.',
            'BOOKING_NOT_PAYABLE' => 'سفارش در وضعیت قابل پرداخت نیست.',
            'BOOKING_ALREADY_PAID' => 'این سفارش قبلاً پرداخت شده است.',
            'PAYMENT_ALREADY_PENDING' => 'برای این سفارش پرداخت در حال انجام است.',
            'INVALID_GATEWAY_CALLBACK' => 'اطلاعات بازگشتی درگاه معتبر نیست.',
            'VERIFIED_TECHNICIAN_REQUIRED' => 'فقط متخصص تأییدشده می‌تواند درخواست تسویه ثبت کند.',
            'INSUFFICIENT_WALLET_BALANCE' => 'ماندهٔ قابل برداشت کیف پول کافی نیست.',
            'SETTLEMENT_AMOUNT_OUT_OF_RANGE' => 'مبلغ تسویه خارج از حداقل یا سقف مجاز است.',
            'DAILY_SETTLEMENT_LIMIT_EXCEEDED' => 'سقف روزانهٔ تسویه رعایت نشده است.',
        ];

        return response()->json([
            'error' => $error,
            'message' => $messages[$error] ?? 'درخواست مالی قابل انجام نیست.',
        ], $status);
    }
}
