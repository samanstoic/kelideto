<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request and check if authenticated user possesses any of the required roles.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'error' => 'UNAUTHENTICATED',
                'message' => 'احراز هویت الزامی است.',
            ], 401);
        }

        if (!DB::table('users')->where('id', $userId)->where('status', 'active')->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'FORBIDDEN_INACTIVE_ACCOUNT',
                'message' => 'حساب کاربری فعال نیست.',
            ], 403);
        }

        $userRoles = DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $userId)
            ->pluck('roles.name')
            ->toArray();

        // Super admin bypass
        if (in_array('super_admin', $userRoles)) {
            return $next($request);
        }

        // Check if user has at least one of the required roles
        $hasRole = !empty(array_intersect($roles, $userRoles));

        if (!$hasRole) {
            return response()->json([
                'success' => false,
                'error' => 'FORBIDDEN_INSUFFICIENT_PERMISSIONS',
                'message' => 'شما مجوز دسترسی به این بخش را ندارید.',
            ], 403);
        }

        return $next($request);
    }
}
