<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    private const ADMIN_ROLES = [
        'super_admin',
        'operator',
        'dispatcher',
        'support',
        'accountant',
        'content_manager',
    ];

    public function __construct(
        protected SmsGatewayInterface $smsGateway
    ) {}

    public function requestOtp(string $phoneNumber): array
    {
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

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(3);
        $otpId = DB::table('otp_verifications')->insertGetId([
            'phone_number' => $phoneNumber,
            'code' => Hash::make($code),
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $this->smsGateway->sendOtp($phoneNumber, $code)) {
            DB::table('otp_verifications')->where('id', $otpId)->delete();

            return [
                'success' => false,
                'error' => 'SMS_PROVIDER_UNAVAILABLE',
                'message' => 'سرویس پیامک در دسترس نیست؛ لطفاً بعداً دوباره تلاش کنید.',
            ];
        }

        return [
            'success' => true,
            'message' => 'کد تایید یکبارمصرف ارسال شد.',
            'expires_in_seconds' => 180,
        ];
    }

    public function verifyOtp(string $phoneNumber, string $code, string $role = 'customer'): array
    {
        $verification = $this->verifyAndConsumeOtp($phoneNumber, $code);
        if (! $verification['success']) {
            return $verification;
        }

        $user = DB::table('users')->where('phone_number', $phoneNumber)->first();

        if (! $user) {
            $userId = DB::table('users')->insertGetId([
                'name' => 'کاربر '.substr($phoneNumber, -4),
                'phone_number' => $phoneNumber,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $roleRecord = DB::table('roles')->where('name', $role)->first();
            if ($roleRecord) {
                DB::table('role_user')->insert([
                    'user_id' => $userId,
                    'role_id' => $roleRecord->id,
                ]);
            }

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

        return $this->authenticateOtpUser((int) $user->id, $phoneNumber, $role);
    }

    private function authenticateOtpUser(int $userId, string $phoneNumber, string $role): array
    {
        return DB::transaction(function () use ($userId, $phoneNumber, $role): array {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                return [
                    'success' => false,
                    'error' => 'USER_NOT_FOUND',
                    'message' => 'کاربر یافت نشد.',
                ];
            }

            $isAdmin = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $userId)
                ->whereIn('roles.name', self::ADMIN_ROLES)
                ->exists();

            if ($isAdmin) {
                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'auth.admin_otp_login_blocked',
                    'auditable_type' => 'App\\Models\\User',
                    'auditable_id' => $userId,
                    'ip_address' => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 1000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'success' => false,
                    'error' => 'ADMIN_OTP_LOGIN_DISABLED',
                    'message' => 'برای ورود مدیریتی از نام کاربری و رمز عبور استفاده کنید.',
                ];
            }

            if ($user->status !== 'active') {
                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'user.authenticated_otp_blocked',
                    'auditable_type' => 'App\\Models\\User',
                    'auditable_id' => $userId,
                    'ip_address' => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 1000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'success' => false,
                    'error' => 'ACCOUNT_INACTIVE',
                    'message' => 'حساب کاربری فعال نیست.',
                ];
            }

            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'action' => 'user.authenticated_otp',
                'auditable_type' => 'App\\Models\\User',
                'auditable_id' => $userId,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => json_encode(['phone' => $phoneNumber, 'role' => $role]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $roles = DB::table('role_user')
                ->join('roles', 'role_user.role_id', '=', 'roles.id')
                ->where('role_user.user_id', $userId)
                ->pluck('roles.name')
                ->toArray();
            $token = User::findOrFail($userId)->createToken('keypro-session')->plainTextToken;

            return [
                'success' => true,
                'message' => 'احراز هویت با موفقیت انجام شد.',
                'data' => [
                    'user' => [
                        'id' => $userId,
                        'name' => $user->name,
                        'phone_number' => $user->phone_number,
                        'status' => $user->status,
                        'roles' => $roles,
                    ],
                    'token' => $token,
                ],
            ];
        }, 3);
    }

    private function verifyAndConsumeOtp(string $phoneNumber, string $code): array
    {
        return DB::transaction(function () use ($phoneNumber, $code): array {
            $session = DB::table('otp_verifications')
                ->where('phone_number', $phoneNumber)
                ->whereNull('verified_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return [
                    'success' => false,
                    'error' => 'OTP_NOT_FOUND',
                    'message' => 'هیچ کد تاییدی برای این شماره ثبت نشده است.',
                ];
            }

            if (now()->isAfter($session->expires_at)) {
                return [
                    'success' => false,
                    'error' => 'OTP_EXPIRED',
                    'message' => 'کد تایید منقضی شده است. لطفاً کد جدید دریافت کنید.',
                ];
            }

            if ($session->attempts >= 3) {
                return [
                    'success' => false,
                    'error' => 'MAX_ATTEMPTS_EXCEEDED',
                    'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً درخواست کد مجدد دهید.',
                ];
            }

            if (! Hash::check($code, $session->code)) {
                DB::table('otp_verifications')->where('id', $session->id)->increment('attempts');

                return [
                    'success' => false,
                    'error' => 'INVALID_CODE',
                    'message' => 'کد تایید واردشده اشتباه است.',
                ];
            }

            $consumed = DB::table('otp_verifications')
                ->where('id', $session->id)
                ->whereNull('verified_at')
                ->update([
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($consumed !== 1) {
                return [
                    'success' => false,
                    'error' => 'OTP_NOT_FOUND',
                    'message' => 'هیچ کد تاییدی برای این شماره ثبت نشده است.',
                ];
            }

            return ['success' => true];
        }, 3);
    }
}