<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Policies\BookingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingAuthorizationController extends Controller
{
    public function verify(Request $request, int $bookingId, BookingPolicy $policy): JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->status !== 'active') {
            return $this->forbidden();
        }

        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        $technician = DB::table('technician_profiles')
            ->where('user_id', $user->id)
            ->where('status', 'verified')
            ->first();
        if (
            !$booking ||
            !$technician ||
            (int) $booking->technician_id !== (int) $technician->id ||
            !$policy->viewCustomerLocation((int) $user->id, $booking)
        ) {
            return $this->forbidden();
        }
        if ($booking->status !== 'arrived') {
            return response()->json([
                'success' => false,
                'error' => 'AUTHORIZATION_VERIFICATION_REQUIRES_ARRIVAL',
                'message' => 'بررسی اختیار مشتری باید پس از حضور در محل و پیش از شروع کار ثبت شود.',
            ], 409);
        }

        $validated = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);
        $result = DB::transaction(function () use ($bookingId, $user, $validated): array {
            $authorization = DB::table('booking_authorizations')
                ->where('booking_id', $bookingId)
                ->lockForUpdate()
                ->first();
            if (!$authorization) {
                return ['error' => 'CUSTOMER_AUTHORIZATION_NOT_RECORDED'];
            }
            if ($authorization->status !== 'customer_attested') {
                return ['error' => 'AUTHORIZATION_ALREADY_REVIEWED'];
            }

            $status = $validated['verified'] ? 'technician_verified' : 'rejected';
            DB::table('booking_authorizations')->where('id', $authorization->id)->update([
                'status' => $status,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => $status];
        });

        if (isset($result['error'])) {
            $notFound = $result['error'] === 'CUSTOMER_AUTHORIZATION_NOT_RECORDED';

            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $notFound
                    ? 'تأیید اختیار مشتری برای این درخواست ثبت نشده است.'
                    : 'تصمیم بررسی اختیار قبلاً ثبت شده است.',
            ], 409);
        }

        return response()->json([
            'data' => [
                'status' => $result['status'],
                'message' => $result['status'] === 'technician_verified'
                    ? 'بررسی اختیار مشتری ثبت شد.'
                    : 'اختیار مشتری احراز نشد؛ کار را متوقف و با پشتیبانی تماس بگیرید.',
            ],
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'FORBIDDEN_BOOKING_AUTHORIZATION',
            'message' => 'فقط متخصص تأییدشده و تخصیص‌یافته می‌تواند بررسی اختیار مشتری را ثبت کند.',
        ], 403);
    }
}
