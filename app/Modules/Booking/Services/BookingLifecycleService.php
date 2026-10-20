<?php

namespace App\Modules\Booking\Services;

use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class BookingLifecycleService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private const TRANSITIONS = [
        'draft' => ['broadcasting', 'cancelled'],
        'broadcasting' => ['assigned', 'cancelled'],
        'assigned' => ['en_route', 'cancelled'],
        'en_route' => ['arrived'],
        'arrived' => ['in_progress'],
        'in_progress' => ['payment_pending', 'disputed'],
        'payment_pending' => ['completed', 'disputed'],
        'disputed' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function recordInitialStatus(int $bookingId, ?int $userId): void
    {
        DB::table('booking_status_history')->insert([
            'booking_id' => $bookingId,
            'from_status' => null,
            'to_status' => 'draft',
            'changed_by_user_id' => $userId,
            'comment' => 'سفارش پس از تأیید برآورد ثبت شد.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function transition(
        int $bookingId,
        string $toStatus,
        ?int $userId,
        ?string $comment = null,
        array $attributes = [],
    ): array {
        return DB::transaction(function () use ($bookingId, $toStatus, $userId, $comment, $attributes): array {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking) {
                return ['error' => 'BOOKING_NOT_FOUND'];
            }

            $allowedAttributes = match ($toStatus) {
                'arrived' => ['arrived_at'],
                'in_progress' => ['started_at'],
                'payment_pending' => [
                    'completed_at',
                    'final_price',
                    'platform_commission_amount',
                    'technician_earnings',
                ],
                default => [],
            };
            if (array_diff(array_keys($attributes), $allowedAttributes)) {
                throw new \InvalidArgumentException('Unsupported booking transition attributes.');
            }
            if ($toStatus === 'cancelled') {
                return ['error' => 'CUSTOMER_CANCELLATION_REQUIRED'];
            }
            if ($booking->status === $toStatus) {
                return ['booking' => $booking, 'already_in_status' => true];
            }

            if (! \in_array($toStatus, self::TRANSITIONS[$booking->status] ?? [], true)) {
                return ['error' => 'INVALID_STATUS_TRANSITION'];
            }

            $now = now();
            $updated = DB::table('bookings')
                ->where('id', $bookingId)
                ->where('status', $booking->status)
                ->update(array_merge($attributes, [
                    'status' => $toStatus,
                    'updated_at' => $now,
                ]));

            if ($updated !== 1) {
                return ['error' => 'STATUS_CONFLICT'];
            }

            DB::table('booking_status_history')->insert([
                'booking_id' => $bookingId,
                'from_status' => $booking->status,
                'to_status' => $toStatus,
                'changed_by_user_id' => $userId,
                'comment' => $comment,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->notifications->notifyBooking(
                $bookingId,
                'booking.status_changed',
                [],
                'booking.status_changed:'.$bookingId.':'.$toStatus,
            );

            return [
                'booking' => DB::table('bookings')->where('id', $bookingId)->first(),
                'already_in_status' => false,
            ];
        }, 3);
    }

    public function cancelByCustomer(
        int $bookingId,
        int $customerId,
        int $userId,
        string $reason,
    ): array {
        return DB::transaction(function () use ($bookingId, $customerId, $userId, $reason): array {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking || (int) $booking->customer_id !== $customerId) {
                return ['error' => 'BOOKING_NOT_FOUND'];
            }

            if ($booking->status === 'cancelled') {
                return ['booking' => $booking, 'already_cancelled' => true];
            }

            if (! \in_array('cancelled', self::TRANSITIONS[$booking->status] ?? [], true)) {
                return ['error' => 'CANCELLATION_NOT_ALLOWED'];
            }

            $now = now();
            $updated = DB::table('bookings')
                ->where('id', $bookingId)
                ->where('customer_id', $customerId)
                ->where('status', $booking->status)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => $now,
                    'cancelled_by' => 'customer',
                    'cancellation_reason' => $reason,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return ['error' => 'STATUS_CONFLICT'];
            }

            DB::table('booking_status_history')->insert([
                'booking_id' => $bookingId,
                'from_status' => $booking->status,
                'to_status' => 'cancelled',
                'changed_by_user_id' => $userId,
                'comment' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->notifications->notifyBooking(
                $bookingId,
                'booking.cancelled',
                [],
                'booking.cancelled:'.$bookingId,
            );

            return [
                'booking' => DB::table('bookings')->where('id', $bookingId)->first(),
                'already_cancelled' => false,
            ];
        }, 3);
    }
}
