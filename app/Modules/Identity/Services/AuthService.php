<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        protected SmsGatewayInterface $smsGateway
    ) {}

    /**
     * Request dynamic OTP code for phone number.
     * Generates a cryptographically random 6-digit code.
     */
    public function requestOtp(string $phoneNumber): array
    {
        // Enforce rate limiting: reject if another code was requested in the last 2 minutes
        $recent = DB::table('otp_verifications')
            ->where('phone_number', $phoneNumber)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->whereNull('verified_at')
            ->first();

        if ($recent) {
            return [
                'success' => false,
                'error' => 'AUTH_RATE_LIMITED',
                'message' => 'کد تایید اخیراً ارسال شده است. لطفاً ۲ دقیقه شکیبا باشید.',
            ];
        }

        // Generate dynamic 6-digit code (e.g. 100000 - 999999)
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(3);

        DB::table('otp_verifications')->insert([
            'phone_number' => $phoneNumber,
            'code' => $code,
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send via configured adapter
        $this->smsGateway->sendOtp($phoneNumber, $code);

        return [
            'success' => true,
            'message' => 'کد تایید یکبارمصرف ارسال شد.',
            'expires_in_seconds' => 180,
        ];
    }

    /**
     * Verify OTP code and login or register customer or technician.
     */
    public function verifyOtp(string $phoneNumber, string $code, string $role = 'customer'): array
    {
        $session = DB::table('otp_verifications')
            ->where('phone_number', $phoneNumber)
            ->whereNull('verified_at')
            ->orderByDesc('id')
            ->first();

        if (!$session) {
            return [
                'success' => false,
                'error' => 'OTP_NOT_FOUND',
                'message' => 'هیچ کد تاییدی برای این شماره ثبت نشده است.',
            ];
        }

        // Check expiration
        if (now()->isAfter($session->expires_at)) {
            return [
                'success' => false,
                'error' => 'OTP_EXPIRED',
                'message' => 'کد تایید منقضی شده است. لطفاً کد جدید دریافت کنید.',
            ];
        }

        // Check attempts limit (Max 3 failed attempts)
        if ($session->attempts >= 3) {
            return [
                'success' => false,
                'error' => 'MAX_ATTEMPTS_EXCEEDED',
                'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً درخواست کد مجدد دهید.',
            ];
        }

        // Validate code
        if ($session->code !== $code) {
            DB::table('otp_verifications')
                ->where('id', $session->id)
                ->increment('attempts');

            return [
                'success' => false,
                'error' => 'INVALID_CODE',
                'message' => 'کد تایید واردشده اشتباه است.',
            ];
        }

        // Mark OTP as verified
        DB::table('otp_verifications')
            ->where('id', $session->id)
            ->update([
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

        // Find or create user
        $user = DB::table('users')->where('phone_number', $phoneNumber)->first();

        if (!$user) {
            $userId = DB::table('users')->insertGetId([
                'name' => 'کاربر ' . substr($phoneNumber, -4),
                'phone_number' => $phoneNumber,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Assign requested role (customer or technician)
            $roleRecord = DB::table('roles')->where('name', $role)->first();
            if ($roleRecord) {
                DB::table('role_user')->insert([
                    'user_id' => $userId,
                    'role_id' => $roleRecord->id,
                ]);
            }

            // Create respective profile
            if ($role === 'technician') {
                DB::table('technician_profiles')->insert([
                    'user_id' => $userId,
                    'status' => 'pending_verification',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('customer_profiles')->insert([
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $user = DB::table('users')->where('id', $userId)->first();
        }

        // Audit log login
        DB::table('audit_logs')->insert([
            'user_id' => $user->id,
            'action' => 'user.authenticated_otp',
            'auditable_type' => 'App\Models\User',
            'auditable_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'new_values' => json_encode(['phone' => $phoneNumber, 'role' => $role]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Get user roles
        $roles = DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $user->id)
            ->pluck('roles.name')
            ->toArray();

        // Create auth token session
        $token = Str::random(60);

        return [
            'success' => true,
            'message' => 'احراز هویت با موفقیت انجام شد.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone_number' => $user->phone_number,
                    'status' => $user->status,
                    'roles' => $roles,
                ],
                'token' => $token,
            ],
        ];
    }
}
