<?php

namespace App\Modules\Identity\Policies;

use Illuminate\Support\Facades\DB;

class BookingPolicy
{
    /**
     * Determine whether the user can view the booking.
     * Prevents IDOR (Insecure Direct Object Reference).
     */
    public function view(int $userId, object $booking): bool
    {
        $userRoles = DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $userId)
            ->pluck('roles.name')
            ->toArray();

        // Super Admin, Operator, and Dispatcher can view all bookings
        if (!empty(array_intersect(['super_admin', 'operator', 'dispatcher'], $userRoles))) {
            return true;
        }

        // Customer can only view if they placed the booking
        $customerProfile = DB::table('customer_profiles')->where('user_id', $userId)->first();
        if ($customerProfile && $booking->customer_id === $customerProfile->id) {
            return true;
        }

        // Technician can only view if assigned to the booking
        $technicianProfile = DB::table('technician_profiles')->where('user_id', $userId)->first();
        if ($technicianProfile && $booking->technician_id === $technicianProfile->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can cancel the booking.
     */
    public function cancel(int $userId, object $booking): bool
    {
        return $this->view($userId, $booking) && in_array($booking->status, ['draft', 'broadcasting', 'assigned']);
    }
}
