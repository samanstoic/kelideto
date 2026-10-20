<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Support\WebAuthn;
use JsonException;
use InvalidArgumentException;
use RangeException;
use SodiumException;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use UnexpectedValueException;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

class AdminPasskeyController extends Controller
{
    private const ADMIN_ROLES = [
        'super_admin',
        'operator',
        'dispatcher',
        'support',
        'accountant',
        'content_manager',
    ];

    public function begin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = User::query()
            ->where(function ($query) use ($credentials) {
                $query->where('username', $credentials['username'])
                    ->orWhere('email', $credentials['username']);
            })
            ->where('status', 'active')
            ->first();

        if (!$user || !$this->isAdmin($user) || !is_string($user->password)
            || !Hash::check($credentials['password'], $user->password)) {
            $request->session()->invalidate();

            return response()->json([
                'success' => false,
                'message' => 'نام کاربری یا رمز عبور مدیر نادرست است.',
            ], 422);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_passkey.pending', [
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]);
        $request->session()->forget('admin_passkey.options');

        return response()->json([
            'success' => true,
            'data' => [
                'mode' => $user->hasPasskeysEnabled() ? 'verify' : 'enroll',
            ],
        ]);
    }

    public function options(
        Request $request,
        GenerateRegistrationOptions $registrationOptions,
        GenerateVerificationOptions $verificationOptions,
    ): JsonResponse {
        $user = $this->pendingAdmin($request);
        abort_unless($user instanceof PasskeyUser, 403, 'احراز هویت مدیر منقضی شده است.');

        if ($user->hasPasskeysEnabled()) {
            $options = $verificationOptions($user);
            $ceremony = 'verification';
        } else {
            $options = $registrationOptions($user);
            $ceremony = 'registration';
        }

        $request->session()->put('admin_passkey.options', [
            'ceremony' => $ceremony,
            'serialized' => WebAuthn::toJson($options),
        ]);

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function register(Request $request, StorePasskey $storePasskey): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ]);

        $user = $this->pendingAdmin($request);
        abort_unless($user instanceof PasskeyUser, 403, 'احراز هویت مدیر منقضی شده است.');
        abort_if($user->hasPasskeysEnabled(), 409, 'ثبت اولیهٔ Passkey قبلاً انجام شده است.');
        $serializedOptions = $this->pullCeremonyOptions($request, 'registration');

        try {
            $credential = $this->credentialFromRequest($data['credential']);
            $options = WebAuthn::fromJson($serializedOptions, PublicKeyCredentialCreationOptions::class);
            $storePasskey($user, $data['name'], $credential, $options);
        } catch (
            InvalidPasskeyException|WebauthnException|ExceptionInterface|JsonException|
            InvalidArgumentException|RangeException|SodiumException|UnexpectedValueException $exception
        ) {
            report($exception);
            throw ValidationException::withMessages([
                'credential' => ['ثبت Passkey انجام نشد؛ دوباره تلاش کنید.'],
            ]);
        }

        return $this->completeLogin($request, $user, 'admin.passkey.enrolled');
    }

    public function verify(Request $request, VerifyPasskey $verifyPasskey): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = $this->pendingAdmin($request);
        abort_unless($user instanceof PasskeyUser, 403, 'احراز هویت مدیر منقضی شده است.');
        abort_unless($user->hasPasskeysEnabled(), 409, 'برای این مدیر Passkey ثبت نشده است.');
        $serializedOptions = $this->pullCeremonyOptions($request, 'verification');

        try {
            $credential = $this->credentialFromRequest($data['credential']);
            $options = WebAuthn::fromJson($serializedOptions, PublicKeyCredentialRequestOptions::class);
            $verifyPasskey($credential, $options, $user);
        } catch (
            InvalidPasskeyException|WebauthnException|ExceptionInterface|JsonException|
            InvalidArgumentException|RangeException|SodiumException|UnexpectedValueException $exception
        ) {
            report($exception);
            throw ValidationException::withMessages([
                'credential' => ['تأیید Passkey ناموفق بود؛ دوباره تلاش کنید.'],
            ]);
        }

        return $this->completeLogin($request, $user, 'admin.login');
    }

    private function pendingAdmin(Request $request): ?User
    {
        $pending = $request->session()->get('admin_passkey.pending');
        if (!is_array($pending)
            || !isset($pending['user_id'], $pending['expires_at'])
            || (int) $pending['expires_at'] < now()->timestamp) {
            $request->session()->forget(['admin_passkey.pending', 'admin_passkey.options']);

            return null;
        }

        $user = User::query()->find($pending['user_id']);

        return $user && $user->status === 'active' && $this->isAdmin($user)
            ? $user
            : null;
    }

    private function pullCeremonyOptions(Request $request, string $expectedCeremony): string
    {
        $ceremony = $request->session()->pull('admin_passkey.options');

        abort_unless(
            is_array($ceremony)
                && ($ceremony['ceremony'] ?? null) === $expectedCeremony
                && is_string($ceremony['serialized'] ?? null),
            422,
            'درخواست Passkey منقضی یا نامعتبر است؛ دوباره تلاش کنید.',
        );

        return $ceremony['serialized'];
    }

    private function credentialFromRequest(array $input): PublicKeyCredential
    {
        return WebAuthn::fromJson(
            json_encode($input, JSON_THROW_ON_ERROR),
            PublicKeyCredential::class,
        );
    }

    private function completeLogin(Request $request, User $user, string $auditAction): JsonResponse
    {
        return DB::transaction(function () use ($request, $user, $auditAction): JsonResponse {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active' && $this->isAdmin($lockedUser), 403);

            $roles = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $lockedUser->id)
                ->pluck('roles.name')
                ->all();
            $adminRole = collect($roles)->first(
                fn (string $role): bool => in_array($role, self::ADMIN_ROLES, true)
            );

            $request->session()->forget(['admin_passkey.pending', 'admin_passkey.options']);
            $request->session()->regenerate();

            DB::table('audit_logs')->insert([
                'user_id' => $lockedUser->id,
                'action' => $auditAction,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'token' => $lockedUser->createToken('admin-browser')->plainTextToken,
                    'user' => [
                        'id' => $lockedUser->id,
                        'name' => $lockedUser->name,
                        'roles' => $roles,
                        'admin_role' => $adminRole,
                    ],
                ],
            ]);
        });
    }

    private function isAdmin(User $user): bool
    {
        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->whereIn('roles.name', self::ADMIN_ROLES)
            ->exists();
    }
}
