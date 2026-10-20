<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Request OTP SMS code.
     * Rate limited by IP and Phone.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'regex:/^09[0-9]{9}$/'],
        ], [
            'phone_number.required' => 'شماره موبایل الزامی است.',
            'phone_number.regex' => 'فرمت شماره موبایل ایران نامعتبر است (مثال: 09121234567).',
        ]);

        $result = $this->authService->requestOtp($validated['phone_number']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $result['message'],
            ], $result['error'] === 'AUTH_RATE_LIMITED' ? 429 : 503);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'expires_in_seconds' => $result['expires_in_seconds'],
            ],
        ], 200);
    }

    /**
     * Verify OTP code and log in.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'regex:/^09[0-9]{9}$/'],
            'code' => ['required', 'string', 'size:6'],
            'role' => ['sometimes', 'string', 'in:customer,technician'],
        ], [
            'phone_number.required' => 'شماره موبایل الزامی است.',
            'code.required' => 'کد تایید الزامی است.',
            'code.size' => 'کد تایید باید ۶ رقم باشد.',
        ]);

        $role = $validated['role'] ?? 'customer';
        $result = $this->authService->verifyOtp($validated['phone_number'], $validated['code'], $role);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'message' => $result['message'],
            ], \in_array($result['error'], ['ADMIN_OTP_LOGIN_DISABLED', 'ACCOUNT_INACTIVE'], true) ? 403 : 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $result['data'],
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (! $userId) {
            return response()->json([
                'success' => false,
                'error' => 'UNAUTHENTICATED',
                'message' => 'احراز هویت الزامی است.',
            ], 401);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'USER_NOT_FOUND',
                'message' => 'کاربر یافت نشد.',
            ], 404);
        }

        $roles = DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $user->id)
            ->pluck('roles.name')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone_number' => $user->phone_number,
                'status' => $user->status,
                'roles' => $roles,
            ],
        ]);
    }
}
