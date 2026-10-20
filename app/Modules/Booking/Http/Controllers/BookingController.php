<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Services\BookingLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customerProfile($request, 'bookings.view_own');
        if (! $customer) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $bookings = DB::table('bookings')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->where('bookings.customer_id', $customer->id)
            ->orderByDesc('bookings.created_at')
            ->orderByDesc('bookings.id')
            ->limit(20)
            ->offset(($page - 1) * 20)
            ->get([
                'bookings.booking_code',
                'bookings.status',
                'bookings.urgency',
                'bookings.estimated_price',
                'bookings.scheduled_at',
                'bookings.created_at',
                'services.title_fa as service_name',
            ]);

        return response()->json([
            'data' => $bookings,
            'meta' => [
                'page' => $page,
                'per_page' => 20,
            ],
        ]);
    }

    public function show(Request $request, string $bookingCode): JsonResponse
    {
        $customer = $this->customerProfile($request, 'bookings.view_own');
        if (! $customer) {
            return $this->forbidden();
        }

        $booking = DB::table('bookings')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->where('bookings.booking_code', $bookingCode)
            ->first([
                'bookings.*',
                'services.title_fa as service_name',
            ]);
        if (! $booking) {
            return $this->notFound();
        }
        if ((int) $booking->customer_id !== (int) $customer->id) {
            return $this->forbidden();
        }

        return response()->json([
            'data' => $this->bookingDetails($booking),
        ]);
    }

    public function cancel(
        Request $request,
        string $bookingCode,
        BookingLifecycleService $lifecycle,
    ): JsonResponse {
        $customer = $this->customerProfile($request, 'bookings.view_own');
        if (! $customer) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $booking = DB::table('bookings')->where('booking_code', $bookingCode)->first();
        if (! $booking) {
            return $this->notFound();
        }
        if ((int) $booking->customer_id !== (int) $customer->id) {
            return $this->forbidden();
        }

        $result = $lifecycle->cancelByCustomer(
            (int) $booking->id,
            (int) $customer->id,
            (int) $request->user()->id,
            $validated['reason'],
        );
        if (isset($result['error'])) {
            $errors = [
                'CANCELLATION_NOT_ALLOWED' => [409, 'با توجه به وضعیت فعلی سفارش، لغو آن دیگر امکان‌پذیر نیست.'],
                'STATUS_CONFLICT' => [409, 'وضعیت سفارش هم‌زمان تغییر کرده است؛ جزئیات را دوباره دریافت کنید.'],
                'BOOKING_NOT_FOUND' => [404, 'سفارش پیدا نشد.'],
            ];
            [$status, $message] = $errors[$result['error']];

            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $message,
            ], $status);
        }

        return response()->json([
            'data' => [
                'booking_code' => $result['booking']->booking_code,
                'status' => $result['booking']->status,
            ],
            'already_cancelled' => $result['already_cancelled'],
        ]);
    }

    private function bookingDetails(object $booking): array
    {
        $pricingSnapshot = $booking->pricing_snapshot !== null
            ? json_decode($booking->pricing_snapshot, true, flags: JSON_THROW_ON_ERROR)
            : null;
        $history = DB::table('booking_status_history')
            ->where('booking_id', $booking->id)
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'comment', 'created_at']);

        return [
            'id' => (int) $booking->id,
            'booking_code' => $booking->booking_code,
            'service_name' => $pricingSnapshot['service_name'] ?? $booking->service_name,
            'status' => $booking->status,
            'urgency' => $booking->urgency,
            'scheduled_at' => $booking->scheduled_at,
            'problem_description' => $booking->problem_description,
            'street_address' => $booking->street_address,
            'latitude' => (float) $booking->latitude,
            'longitude' => (float) $booking->longitude,
            'estimated_price' => (float) $booking->estimated_price,
            'tariff_version' => $booking->tariff_version,
            'pricing_snapshot' => $pricingSnapshot,
            'created_at' => $booking->created_at,
            'cancellation_reason' => $booking->cancellation_reason,
            'status_history' => $history,
            'can_cancel' => \in_array($booking->status, ['draft', 'broadcasting', 'assigned'], true),
        ];
    }

    private function customerProfile(Request $request, string $permission): ?object
    {
        $user = $request->user();
        if (! $user || $user->status !== 'active') {
            return null;
        }

        $permitted = DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $user->id)
            ->where('roles.name', 'customer')
            ->where('permissions.name', $permission)
            ->exists();
        if (! $permitted) {
            return null;
        }

        return DB::table('customer_profiles')->where('user_id', $user->id)->first();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'FORBIDDEN_BOOKING_ACCESS',
            'message' => 'دسترسی به سفارش‌های این حساب مجاز نیست.',
        ], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'BOOKING_NOT_FOUND',
            'message' => 'سفارش پیدا نشد.',
        ], 404);
    }
}
