<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Services\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DispatchController extends Controller
{
    public function issueOffer(Request $request, int $bookingId, DispatchService $dispatch): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->hasPermission((int) $user->id, 'technicians.manage_dispatch', ['super_admin', 'dispatcher'])) {
            return $this->error('FORBIDDEN_DISPATCH', 'ثبت پیشنهاد اعزام فقط برای مسئول اعزام مجاز است.', 403);
        }

        $validated = $request->validate([
            'technician_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'technician_ids.*' => ['required', 'integer', 'distinct', 'exists:technician_profiles,id'],
            'response_window_seconds' => ['sometimes', 'integer', 'between:30,600'],
        ]);
        $result = $dispatch->issueNextOffer(
            $bookingId,
            (int) $user->id,
            $validated['response_window_seconds'] ?? (int) config('keypro.dispatch.response_window_seconds', 90),
            $validated['technician_ids'] ?? null,
        );
        if (($result['error'] ?? null) === 'NO_ELIGIBLE_TECHNICIANS' && ! isset($validated['technician_ids'])) {
            return response()->json([
                'data' => [
                    'status' => 'no_eligible_technicians',
                    'estimated_travel_time_minutes' => null,
                    'travel_time_status' => 'unavailable',
                ],
                'message' => 'متخصص واجد شرایطی برای این مأموریت پیدا نشد.',
            ]);
        }
        if (isset($result['error'])) {
            return $this->dispatchError($result['error']);
        }

        return response()->json([
            'data' => $result + ['offer_ids' => [$result['offer_id']]],
        ], 201);
    }

    public function respondToOffer(Request $request, int $offerId, DispatchService $dispatch): JsonResponse
    {
        $user = $request->user();
        $profile = $user
            ? DB::table('technician_profiles')->where('user_id', $user->id)->first()
            : null;
        if (! $user || ! $profile || ! $this->hasRole((int) $user->id, 'technician')) {
            return $this->error('TECHNICIAN_PROFILE_REQUIRED', 'پروفایل متخصص پیدا نشد.', 403);
        }
        $validated = $request->validate([
            'decision' => ['required', 'in:accepted,rejected'],
        ]);
        $result = $dispatch->respondToOffer(
            $offerId,
            (int) $profile->id,
            (int) $user->id,
            $validated['decision'],
        );
        if (isset($result['error'])) {
            return $this->offerError($result['error']);
        }
        if (($result['status'] ?? null) === 'expired') {
            return response()->json([
                'success' => false,
                'error' => 'OFFER_EXPIRED',
                'message' => 'مهلت پاسخ‌گویی به پیشنهاد پایان یافت؛ در صورت وجود متخصص بعدی، پیشنهاد برای او ارسال شد.',
                'data' => $result,
            ], 409);
        }

        return response()->json(['data' => $result]);
    }

    public function assignManually(Request $request, int $bookingId, DispatchService $dispatch): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->hasPermission((int) $user->id, 'bookings.dispatch_manual', ['super_admin', 'operator'])) {
            return $this->error('FORBIDDEN_MANUAL_DISPATCH', 'تخصیص دستی فقط برای اپراتور مجاز است.', 403);
        }
        $validated = $request->validate([
            'technician_id' => ['required', 'integer', 'exists:technician_profiles,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $result = $dispatch->assignManually(
            $bookingId,
            (int) $validated['technician_id'],
            (int) $user->id,
            $validated['reason'],
        );
        if (isset($result['error'])) {
            return $this->dispatchError($result['error']);
        }

        return response()->json(['data' => $result], 201);
    }

    public function events(Request $request, int $bookingId, DispatchService $dispatch): JsonResponse
    {
        $user = $request->user();
        if (! $user || (
            ! $this->hasPermission((int) $user->id, 'technicians.manage_dispatch', ['super_admin', 'dispatcher']) &&
            ! $this->hasPermission((int) $user->id, 'bookings.dispatch_manual', ['super_admin', 'operator'])
        )) {
            return $this->error('FORBIDDEN_DISPATCH', 'مشاهدهٔ تاریخچهٔ اعزام فقط برای مسئول اعزام مجاز است.', 403);
        }
        if (! DB::table('bookings')->where('id', $bookingId)->exists()) {
            return $this->error('BOOKING_NOT_FOUND', 'سفارش پیدا نشد.', 404);
        }

        return response()->json(['data' => $dispatch->events($bookingId)]);
    }

    private function hasPermission(int $userId, string $permission, array $roles): bool
    {
        return DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'role_user.user_id', '=', 'users.id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $userId)
            ->where('users.status', 'active')
            ->whereIn('roles.name', $roles)
            ->where('permissions.name', $permission)
            ->exists();
    }

    private function hasRole(int $userId, string $role): bool
    {
        return DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $userId)
            ->where('roles.name', $role)
            ->exists();
    }

    private function dispatchError(string $error): JsonResponse
    {
        $errors = [
            'BOOKING_NOT_FOUND' => [404, 'سفارش برای اعزام پیدا نشد.'],
            'BOOKING_NOT_DISPATCHABLE' => [409, 'سفارش در وضعیت فعلی قابل اعزام نیست.'],
            'SERVICE_AREA_UNAVAILABLE' => [422, 'محدودهٔ فعال این سفارش مشخص نیست.'],
            'TECHNICIAN_NOT_ELIGIBLE' => [422, 'متخصص انتخاب‌شده تأییدشده یا واجد شرایط این سفارش نیست.'],
            'TECHNICIAN_CAPACITY_REACHED' => [409, 'ظرفیت یا ساعت کاری متخصص برای این سفارش کافی نیست.'],
            'DISPATCH_OFFER_ALREADY_PENDING' => [409, 'برای این سفارش هنوز پیشنهاد معتبر در انتظار پاسخ است.'],
            'NO_ELIGIBLE_TECHNICIANS' => [200, 'متخصص واجد شرایطی برای این مأموریت پیدا نشد.'],
        ];
        [$status, $message] = $errors[$error] ?? [409, 'عملیات اعزام انجام نشد.'];

        return $this->error($error, $message, $status);
    }

    private function offerError(string $error): JsonResponse
    {
        $errors = [
            'OFFER_NOT_FOUND' => [404, 'پیشنهاد مأموریت پیدا نشد.'],
            'OFFER_ALREADY_RESPONDED' => [409, 'به این پیشنهاد قبلاً پاسخ داده شده است.'],
            'OFFER_EXPIRED' => [409, 'مهلت پاسخ‌گویی به پیشنهاد پایان یافته است.'],
            'BOOKING_ALREADY_ASSIGNED' => [409, 'این مأموریت قبلاً به متخصص دیگری تخصیص یافته است.'],
            'TECHNICIAN_NOT_VERIFIED' => [403, 'مدارک متخصص تأیید نشده یا اعتبار آن لغو شده است.'],
            'TECHNICIAN_NOT_AVAILABLE' => [409, 'برای پذیرش مأموریت باید آنلاین و آزاد باشید.'],
            'TECHNICIAN_NOT_ELIGIBLE' => [403, 'این مأموریت با مهارت، منطقه یا ظرفیت کاری شما تطبیق ندارد.'],
        ];
        [$status, $message] = $errors[$error] ?? [409, 'پاسخ به پیشنهاد مأموریت ممکن نشد.'];

        return $this->error($error, $message, $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $code, 'message' => $message], $status);
    }
}
