<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Policies\BookingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingLocationController extends Controller
{
    public function customerLocation(Request $request, int $bookingId, BookingPolicy $policy): JsonResponse
    {
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        if (!$booking) {
            return response()->json([
                'success' => false,
                'error' => 'BOOKING_NOT_FOUND',
                'message' => 'سفارش پیدا نشد.',
            ], 404);
        }

        $userId = (int) $request->user()->id;
        if (!$policy->viewCustomerLocation($userId, $booking)) {
            return response()->json([
                'success' => false,
                'error' => 'FORBIDDEN_BOOKING_LOCATION',
                'message' => 'دسترسی به نشانی مشتری فقط برای متخصص تأییدشدهٔ سفارش فعال مجاز است.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'latitude' => (float) $booking->latitude,
                'longitude' => (float) $booking->longitude,
                'address' => $booking->street_address,
                'access_expires_at' => $booking->location_access_expires_at,
            ],
        ]);
    }
}
