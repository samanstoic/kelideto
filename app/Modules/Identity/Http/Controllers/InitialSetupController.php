<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Payment\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InitialSetupController extends Controller
{
    private const REQUIRED_TABLES = [
        'users',
        'roles',
        'permissions',
        'role_user',
        'permission_role',
        'personal_access_tokens',
        'app_settings',
        'security_policies',
        'audit_logs',
        'installation_state',
    ];

    public function status(
        NotificationService $notifications,
        PaymentService $payments,
    ): JsonResponse {
        $databaseReady = false;
        $schemaReady = false;
        $setupRequired = true;

        try {
            DB::connection()->getPdo();
            $databaseReady = true;
            $schemaReady = collect(self::REQUIRED_TABLES)->every(
                fn (string $table): bool => Schema::hasTable($table)
            );
            if ($schemaReady) {
                $setupRequired = ! DB::table('installation_state')
                    ->where('id', 1)
                    ->whereNotNull('completed_at')
                    ->exists()
                    && ! $this->hasSuperAdmin();
            }
        } catch (QueryException) {
            $databaseReady = false;
        }

        $configuredKey = config('app.key');
        $applicationKey = is_string($configuredKey) && str_starts_with($configuredKey, 'base64:')
            ? base64_decode(substr($configuredKey, 7), true)
            : $configuredKey;
        $applicationKeyReady = is_string($applicationKey)
            && Encrypter::supported($applicationKey, (string) config('app.cipher'));
        $setupToken = config('app.initial_setup_token');
        $setupTokenReady = is_string($setupToken) && strlen($setupToken) >= 32;
        $passkeyConfigurationReady = $this->passkeyConfigurationReady();
        $productionConfigurationReady = ! app()->environment('production') || ! config('app.debug');
        $ready = $databaseReady && $schemaReady && $applicationKeyReady
            && $setupTokenReady && $passkeyConfigurationReady && $productionConfigurationReady;
        $notificationStatus = $notifications->serviceStatus();
        $paymentStatus = $payments->readiness();

        return response()->json([
            'data' => [
                'setup_required' => $setupRequired,
                'ready' => $ready,
                'requirements' => [
                    ['key' => 'database', 'label' => 'اتصال پایگاه داده', 'ready' => $databaseReady],
                    ['key' => 'migrations', 'label' => 'اجرای migrationهای پروژه', 'ready' => $schemaReady],
                    ['key' => 'application_key', 'label' => 'تنظیم کلید رمزنگاری APP_KEY', 'ready' => $applicationKeyReady],
                    ['key' => 'setup_token', 'label' => 'تنظیم کلید یک‌بارمصرف نصب INITIAL_SETUP_TOKEN', 'ready' => $setupTokenReady],
                    ['key' => 'admin_passkeys', 'label' => 'تنظیم شناسه دامنه و مبدأهای Passkey مدیر', 'ready' => $passkeyConfigurationReady],
                    ['key' => 'production_debug', 'label' => 'غیرفعال بودن حالت اشکال‌زدایی در محیط عملیاتی', 'ready' => $productionConfigurationReady],
                ],
                'services' => [
                    'sms' => $notificationStatus['sms'],
                    'web_push' => $notificationStatus['web_push'],
                    'payment_gateway' => [
                        'provider' => $paymentStatus['gateway'],
                        'status' => $paymentStatus['status'],
                    ],
                    'admin_passkeys' => [
                        'status' => $passkeyConfigurationReady ? 'ready' : 'misconfigured',
                    ],
                ],
            ],
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $this->ensureSetupPrerequisites();
        abort_if($this->installationCompleted(), 409, 'راه‌اندازی قبلاً انجام شده است.');
        $providedSetupToken = (string) $request->input('setup_token', '');
        abort_unless(hash_equals((string) config('app.initial_setup_token'), $providedSetupToken), 403,
            'کلید یک‌بارمصرف نصب معتبر نیست.');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
            'settings' => ['required', 'array'],
            'settings.site_name' => ['required', 'string', 'min:2', 'max:100'],
            'settings.contact_email' => ['nullable', 'email', 'max:255'],
            'settings.support_phone' => ['nullable', 'string', 'regex:/^09[0-9]{9}$/'],
            'settings.timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'settings.dispatch_response_window_seconds' => ['required', 'integer', 'between:30,600'],
            'settings.location_retention_hours' => ['required', 'integer', 'between:1,8'],
        ], [
            'password.min' => 'رمز عبور مدیر باید دست‌کم ۱۲ نویسه باشد.',
            'password.confirmed' => 'تکرار رمز عبور با رمز واردشده یکسان نیست.',
            'settings.support_phone.regex' => 'شماره پشتیبانی را با قالب ۰۹ و ۱۱ رقم وارد کنید.',
        ]);

        try {
            $user = DB::transaction(function () use ($data, $request): User {
                $state = DB::table('installation_state')->where('id', 1)->lockForUpdate()->first();
                abort_unless($state, 503, 'وضعیت نصب موجود نیست؛ migrationهای پروژه را اجرا کنید.');
                abort_if($state->completed_at || $this->hasSuperAdmin(), 409, 'راه‌اندازی قبلاً انجام شده است.');

                app(RolesAndPermissionsSeeder::class)->run();
                $userId = DB::table('users')->insertGetId([
                    'name' => $data['name'],
                    'username' => $data['username'],
                    'email' => $data['email'] ?? null,
                    'password' => Hash::make($data['password']),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $adminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
                abort_unless($adminRoleId, 500, 'نقش مدیر ارشد ایجاد نشد.');
                DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $adminRoleId]);

                $settings = $this->settingsFromRequest($data['settings']);
                foreach ($settings as $key => $value) {
                    DB::table('app_settings')->updateOrInsert(
                        ['setting_key' => $key],
                        [
                            'setting_value' => json_encode($value, JSON_THROW_ON_ERROR),
                            'category' => str_contains($key, 'dispatch.') ? 'dispatch' : 'general',
                            'is_sensitive' => false,
                            'updated_by_user_id' => $userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }

                DB::table('security_policies')->updateOrInsert(
                    ['policy_key' => 'admin.location_retention_hours'],
                    [
                        'policy_value' => json_encode([
                            'hours' => (int) $data['settings']['location_retention_hours'],
                        ], JSON_THROW_ON_ERROR),
                        'reason' => 'تعیین محدودیت نگهداری موقعیت هنگام راه‌اندازی اولیه.',
                        'updated_by_user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                DB::table('installation_state')->where('id', 1)->update([
                    'completed_by_user_id' => $userId,
                    'completed_at' => now(),
                ]);
                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'system.initial_setup.completed',
                    'auditable_type' => 'installation',
                    'auditable_id' => 1,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                    'new_values' => json_encode([
                        'username' => $data['username'],
                        'settings' => $settings,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return User::query()->findOrFail($userId);
            }, 3);
        } catch (QueryException $exception) {
            if ($this->hasSuperAdmin()) {
                throw ValidationException::withMessages([
                    'setup' => 'راه‌اندازی هم‌زمان دیگری کامل شد. صفحه را تازه‌سازی کنید.',
                ]);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'راه‌اندازی اولیه با موفقیت انجام شد. اکنون با اطلاعات مدیر وارد شوید.',
            'data' => ['setup_required' => false, 'user' => ['id' => $user->id, 'username' => $user->username]],
        ], 201);
    }

    public function adminLogin(Request $request): JsonResponse
    {
        return app(AdminPasskeyController::class)->begin($request);
    }

    private function ensureSetupPrerequisites(): void
    {
        $configuredKey = config('app.key');
        $applicationKey = is_string($configuredKey) && str_starts_with($configuredKey, 'base64:')
            ? base64_decode(substr($configuredKey, 7), true)
            : $configuredKey;
        abort_unless(
            is_string($applicationKey) && Encrypter::supported($applicationKey, (string) config('app.cipher')),
            503,
            'پیش از نصب، یک APP_KEY معتبر را در محیط سرور تنظیم کنید.'
        );
        $setupToken = config('app.initial_setup_token');
        abort_unless(is_string($setupToken) && strlen($setupToken) >= 32, 503,
            'پیش از نصب، یک INITIAL_SETUP_TOKEN تصادفی با حداقل ۳۲ نویسه در محیط سرور تنظیم کنید.');
        abort_unless($this->passkeyConfigurationReady(), 503,
            'پیش از نصب، دامنه و مبدأهای HTTPS مربوط به Passkey مدیر را تنظیم کنید.');
        abort_if(app()->environment('production') && config('app.debug'), 503, 'در محیط عملیاتی APP_DEBUG باید خاموش باشد.');

        foreach (self::REQUIRED_TABLES as $table) {
            abort_unless(Schema::hasTable($table), 503, 'پیش از نصب، migrationهای پروژه را اجرا کنید.');
        }
    }

    private function hasSuperAdmin(): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->where('roles.name', 'super_admin')
            ->exists();
    }

    private function installationCompleted(): bool
    {
        return DB::table('installation_state')
            ->where('id', 1)
            ->whereNotNull('completed_at')
            ->exists()
            || $this->hasSuperAdmin();
    }

    private function passkeyConfigurationReady(): bool
    {
        $relyingPartyId = config('passkeys.relying_party_id');
        $origins = config('passkeys.allowed_origins');
        if (!is_string($relyingPartyId) || $relyingPartyId === ''
            || !is_array($origins) || $origins === []) {
            return false;
        }
        if (filter_var($relyingPartyId, FILTER_VALIDATE_IP) === false
            && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $relyingPartyId)) {
            return false;
        }
        if (app()->environment('production') && !config('passkeys.explicitly_configured')) {
            return false;
        }

        foreach ($origins as $origin) {
            if (!is_string($origin)) {
                return false;
            }
            $parts = parse_url($origin);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $path = is_array($parts) ? ($parts['path'] ?? '') : '';
            $secureOrigin = $scheme === 'https'
                || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
            $matchesRelyingParty = $host === strtolower($relyingPartyId)
                || str_ends_with($host, '.'.strtolower($relyingPartyId));

            if (!$secureOrigin || !$matchesRelyingParty
                || !in_array($path, ['', '/'], true)
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                return false;
            }
        }

        return true;
    }

    private function settingsFromRequest(array $settings): array
    {
        return [
            'site.name' => $settings['site_name'],
            'site.contact_email' => $settings['contact_email'] ?? null,
            'site.support_phone' => $settings['support_phone'] ?? null,
            'app.timezone' => $settings['timezone'],
            'app.locale' => 'fa',
            'finance.currency' => 'IRR',
            'dispatch.response_window_seconds' => (int) $settings['dispatch_response_window_seconds'],
        ];
    }
}
