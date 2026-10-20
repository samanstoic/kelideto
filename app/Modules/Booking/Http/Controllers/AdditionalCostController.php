<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Services\TariffPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdditionalCostController extends Controller
{
    public function index(Request $request, int $bookingId): JsonResponse
    {
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        if (!$booking || !$this->canView($request, $booking)) {
            return $this->forbiddenOrNotFound($booking);
        }

        $costs = DB::table('scope_change_requests')
            ->join('services', 'scope_change_requests.additional_service_id', '=', 'services.id')
            ->where('scope_change_requests.booking_id', $bookingId)
            ->orderBy('scope_change_requests.id')
            ->get([
                'scope_change_requests.id',
                'scope_change_requests.reason',
                'scope_change_requests.additional_amount',
                'scope_change_requests.quantity',
                'scope_change_requests.status',
                'scope_change_requests.pricing_snapshot',
                'scope_change_requests.created_at',
                'services.title_fa as service_name',
            ]);
        $costs->transform(function (object $cost): object {
            $cost->additional_amount = (float) $cost->additional_amount;
            $cost->pricing_snapshot = json_decode($cost->pricing_snapshot, true, flags: JSON_THROW_ON_ERROR);

            return $cost;
        });

        return response()->json(['data' => $costs]);
    }

    public function store(Request $request, int $bookingId, TariffPricingService $pricing): JsonResponse
    {
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        $technician = $this->assignedTechnician($request, $booking);
        if (!$technician) {
            return $this->forbiddenOrNotFound($booking);
        }
        if ($booking->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'error' => 'BOOKING_NOT_IN_PROGRESS',
                'message' => 'ثبت هزینهٔ افزوده فقط هنگام انجام درخواست ممکن است.',
            ], 409);
        }

        $validated = $request->validate([
            'additional_service_id' => ['required', 'integer', 'exists:services,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        $quote = $pricing->quote(
            (int) $validated['additional_service_id'],
            (float) $booking->latitude,
            (float) $booking->longitude
        );
        if ($quote['service']->requires_ownership_proof &&
            !DB::table('booking_authorizations')
                ->where('booking_id', $booking->id)
                ->where('status', 'technician_verified')
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'error' => 'TECHNICIAN_AUTHORITY_CHECK_REQUIRED',
                'message' => 'پیش از ثبت این هزینه، متخصص باید اختیار مشتری را در محل بررسی و ثبت کند.',
            ], 422);
        }
        $quantity = (int) ($validated['quantity'] ?? 1);
        $amount = round($quote['subtotal'] * $quantity, 2);
        $snapshot = $quote['snapshot'];
        $snapshot['quantity'] = $quantity;
        $snapshot['unit_estimated_subtotal'] = $quote['subtotal'];
        $snapshot['estimated_additional_amount'] = $amount;
        $snapshot['approval_required_before_charge'] = true;
        $now = now();
        $id = DB::table('scope_change_requests')->insertGetId([
            'booking_id' => $booking->id,
            'technician_id' => $technician->id,
            'additional_service_id' => $quote['service']->id,
            'service_tariff_id' => $quote['tariff']->id,
            'tariff_version' => $quote['tariff']->version,
            'quantity' => $quantity,
            'reason' => $validated['reason'],
            'additional_amount' => $amount,
            'pricing_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'data' => DB::table('scope_change_requests')->find($id),
            'message' => 'هزینهٔ افزوده برای تأیید مشتری ثبت شد و تا آن زمان قابل مطالبه نیست.',
        ], 201);
    }

    public function respond(Request $request, int $bookingId, int $costId): JsonResponse
    {
        $customer = $this->customerOwnsBooking($request, $bookingId);
        if (!$customer) {
            return $this->forbiddenOrNotFound(DB::table('bookings')->where('id', $bookingId)->first());
        }
        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
        ]);

        $result = DB::transaction(function () use ($request, $bookingId, $costId, $customer, $validated): array {
            $cost = DB::table('scope_change_requests')
                ->where('id', $costId)
                ->where('booking_id', $bookingId)
                ->lockForUpdate()
                ->first();
            if (!$cost) {
                return ['error' => 'COST_NOT_FOUND'];
            }
            if ($cost->status !== 'pending') {
                return ['error' => 'COST_ALREADY_ANSWERED'];
            }

            $now = now();
            $approved = (bool) $validated['approved'];
            DB::table('customer_approvals')->insert([
                'scope_change_id' => $cost->id,
                'customer_id' => $customer->id,
                'approved' => $approved,
                'approval_method' => 'app_click',
                'customer_ip' => $request->ip(),
                'responded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('scope_change_requests')->where('id', $cost->id)->update([
                'status' => $approved ? 'approved' : 'rejected',
                'updated_at' => $now,
            ]);

            return ['approved' => $approved];
        });

        if (isset($result['error'])) {
            $notFound = $result['error'] === 'COST_NOT_FOUND';

            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $notFound ? 'هزینهٔ افزوده پیدا نشد.' : 'برای این هزینه قبلاً پاسخ ثبت شده است.',
            ], $notFound ? 404 : 409);
        }

        return response()->json([
            'data' => [
                'status' => $result['approved'] ? 'approved' : 'rejected',
                'message' => $result['approved']
                    ? 'هزینهٔ افزوده تأیید شد.'
                    : 'هزینهٔ افزوده رد شد و قابل مطالبه نیست.',
            ],
        ]);
    }

    private function assignedTechnician(Request $request, ?object $booking): ?object
    {
        $user = $request->user();
        if (!$user || $user->status !== 'active' || !$booking) {
            return null;
        }

        $technician = DB::table('technician_profiles')
            ->where('user_id', $user->id)
            ->where('status', 'verified')
            ->first();

        if (!$technician || (int) $booking->technician_id !== (int) $technician->id) {
            return null;
        }

        return $technician;
    }

    private function customerOwnsBooking(Request $request, int $bookingId): ?object
    {
        $user = $request->user();
        if (!$user || $user->status !== 'active') {
            return null;
        }

        $customer = DB::table('customer_profiles')->where('user_id', $user->id)->first();
        $booking = DB::table('bookings')->where('id', $bookingId)->first();

        return $customer && $booking && (int) $booking->customer_id === (int) $customer->id
            ? $customer
            : null;
    }

    private function canView(Request $request, object $booking): bool
    {
        $user = $request->user();
        if (!$user || $user->status !== 'active') {
            return false;
        }

        $customer = DB::table('customer_profiles')->where('user_id', $user->id)->first();
        if ($customer && (int) $booking->customer_id === (int) $customer->id) {
            return true;
        }

        $technician = DB::table('technician_profiles')
            ->where('user_id', $user->id)
            ->where('status', 'verified')
            ->first();

        return $technician && (int) $booking->technician_id === (int) $technician->id;
    }

    private function forbiddenOrNotFound(?object $booking): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $booking ? 'FORBIDDEN_BOOKING_ACCESS' : 'BOOKING_NOT_FOUND',
            'message' => $booking
                ? 'دسترسی به این درخواست مجاز نیست.'
                : 'درخواست پیدا نشد.',
        ], $booking ? 403 : 404);
    }
}
