<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Services\LocationAccessWindow;
use Illuminate\Support\Facades\DB;

class BookingPolicy
{
    public function viewCustomerLocation(int $userId, object $booking): bool
    {
        $user = DB::table('users')->where('id', $userId)->where('status', 'active')->first();
        $technician = DB::table('technician_profiles')
            ->where('user_id', $userId)
            ->where('status', 'verified')
            ->first();

        if (!$user || !$technician || (int) $booking->technician_id !== (int) $technician->id) {
            return false;
        }

        $assignment = DB::table('assignments')
            ->where('booking_id', $booking->id)
            ->where('technician_id', $technician->id)
            ->where('status', 'active')
            ->first();

        if (
            !in_array($booking->status, ['assigned', 'en_route', 'arrived', 'in_progress'], true) ||
            empty($booking->location_access_expires_at) ||
            now()->greaterThanOrEqualTo($booking->location_access_expires_at) ||
            ! $assignment ||
            empty($assignment->assigned_at) ||
            now()->greaterThanOrEqualTo(
                \Illuminate\Support\Carbon::parse($assignment->assigned_at)
                    ->addHours(app(LocationAccessWindow::class)->hours())
            )
        ) {
            return false;
        }

        return DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $userId)
            ->where('roles.name', 'technician')
            ->where('permissions.name', 'bookings.view_assigned_location')
            ->exists();
    }

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
