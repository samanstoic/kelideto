<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    private const EVENTS = [
        'booking.status_changed',
        'booking.cancelled',
        'booking.delayed',
        'booking.rescheduled',
        'dispatch.offer.issued',
    ];

    private const CHANNELS = ['in_app', 'sms', 'web_push'];

    public function index(Request $request): JsonResponse
    {
        $notifications = DB::table('notifications_log')
            ->where('user_id', $request->user()->id)
            ->where('channel', 'in_app')
            ->whereNotNull('event_key')
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id',
                'event_key',
                'body',
                'payload',
                'provider_status',
                'delivery_status',
                'read_at',
                'created_at',
            ])
            ->map(static function (object $notification): object {
                $payload = json_decode((string) $notification->payload, true);
                $notification->title = $payload['title'] ?? null;
                $notification->url = $payload['url'] ?? null;
                unset($notification->payload);

                return $notification;
            });

        return response()->json(['data' => $notifications]);
    }

    public function markRead(Request $request, int $notificationId): JsonResponse
    {
        $updated = DB::table('notifications_log')
            ->where('id', $notificationId)
            ->where('user_id', $request->user()->id)
            ->where('channel', 'in_app')
            ->whereNotNull('event_key')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        if (! $updated && ! DB::table('notifications_log')
            ->where('id', $notificationId)
            ->where('user_id', $request->user()->id)
            ->where('channel', 'in_app')
            ->whereNotNull('read_at')
            ->exists()) {
            return $this->error('NOTIFICATION_NOT_FOUND', 'اعلان پیدا نشد.', 404);
        }

        return response()->json(['data' => ['read' => true]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'data' => DB::table('notification_preferences')
                ->where('user_id', $request->user()->id)
                ->orderBy('event_key')
                ->orderBy('channel')
                ->get(['event_key', 'channel', 'enabled', 'hourly_limit']),
            'defaults' => [
                'in_app' => true,
                'sms' => true,
                'web_push' => false,
                'hourly_limit' => 10,
            ],
        ]);
    }

    public function updatePreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_key' => ['required', Rule::in(self::EVENTS)],
            'channel' => ['required', Rule::in(self::CHANNELS)],
            'enabled' => ['required', 'boolean'],
            'hourly_limit' => ['sometimes', 'integer', 'between:1,20'],
        ]);
        $now = now();
        DB::table('notification_preferences')->updateOrInsert(
            [
                'user_id' => $request->user()->id,
                'event_key' => $validated['event_key'],
                'channel' => $validated['channel'],
            ],
            [
                'enabled' => $validated['enabled'],
                'hourly_limit' => $validated['hourly_limit'] ?? 10,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return response()->json(['data' => ['updated' => true]]);
    }

    public function webPushPublicKey(): JsonResponse
    {
        $status = app(NotificationService::class)->serviceStatus()['web_push'];
        if ($status['status'] !== 'connected') {
            return $this->error('WEB_PUSH_DISCONNECTED', 'سرویس اعلان وب‌پوش فاقد تنظیمات یا اعتبارنامهٔ معتبر است.', 503);
        }

        return response()->json(['data' => ['public_key' => config('services.webpush.public_key')]]);
    }

    public function storeWebPushSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consent' => ['required', 'accepted'],
            'subscription.endpoint' => ['required', 'string', 'url', 'max:2048'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:512'],
            'subscription.keys.auth' => ['required', 'string', 'max:256'],
            'subscription.contentEncoding' => ['sometimes', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);
        $endpoint = $validated['subscription']['endpoint'];
        if (! $this->isBase64UrlKey($validated['subscription']['keys']['p256dh'], 65) ||
            ! $this->isBase64UrlKey($validated['subscription']['keys']['auth'], 16)) {
            return $this->error('INVALID_PUSH_KEYS', 'کلیدهای اشتراک Push معتبر نیستند.', 422);
        }
        if (! $this->isSupportedPushEndpoint($endpoint)) {
            return $this->error('INVALID_PUSH_ENDPOINT', 'نشانی سرویس Push پشتیبانی نمی‌شود.', 422);
        }
        if (app(NotificationService::class)->serviceStatus()['web_push']['status'] !== 'connected') {
            return $this->error('WEB_PUSH_DISCONNECTED', 'سرویس اعلان وب‌پوش در دسترس نیست.', 503);
        }

        $endpointHash = hash('sha256', $endpoint);
        $existing = DB::table('web_push_subscriptions')->where('endpoint_hash', $endpointHash)->first();
        if ($existing && (int) $existing->user_id !== (int) $request->user()->id) {
            return $this->error('PUSH_SUBSCRIPTION_OWNED_BY_ANOTHER_USER', 'این اشتراک اعلان متعلق به حساب دیگری است.', 409);
        }
        $now = now();
        DB::table('web_push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => $endpointHash],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $endpoint,
                'public_key' => $validated['subscription']['keys']['p256dh'],
                'auth_token' => $validated['subscription']['keys']['auth'],
                'content_encoding' => $validated['subscription']['contentEncoding'] ?? 'aes128gcm',
                'consented_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    public function deleteWebPushSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2048'],
        ]);
        DB::table('web_push_subscriptions')
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $validated['endpoint']))
            ->delete();

        return response()->json(['data' => ['unsubscribed' => true]]);
    }

    public function services(Request $request, NotificationService $notifications): JsonResponse
    {
        if (! $this->can($request, 'notifications.manage')) {
            return $this->error('FORBIDDEN_NOTIFICATIONS', 'مشاهدهٔ وضعیت سرویس اعلان مجاز نیست.', 403);
        }

        return response()->json(['data' => $notifications->serviceStatus()]);
    }

    public function templates(Request $request): JsonResponse
    {
        if (! $this->can($request, 'notifications.templates.manage')) {
            return $this->error('FORBIDDEN_TEMPLATES', 'مدیریت قالب اعلان فقط برای مدیر ارشد مجاز است.', 403);
        }

        return response()->json([
            'data' => DB::table('notification_templates')
                ->orderBy('event_key')
                ->orderBy('channel')
                ->get(['id', 'event_key', 'channel', 'title_fa', 'body_fa', 'is_active', 'updated_at']),
        ]);
    }

    public function updateTemplate(Request $request, string $eventKey, string $channel): JsonResponse
    {
        if (! $this->can($request, 'notifications.templates.manage')) {
            return $this->error('FORBIDDEN_TEMPLATES', 'مدیریت قالب اعلان فقط برای مدیر ارشد مجاز است.', 403);
        }
        if (! in_array($eventKey, self::EVENTS, true) || ! in_array($channel, self::CHANNELS, true)) {
            return $this->error('TEMPLATE_NOT_FOUND', 'قالب اعلان پیدا نشد.', 404);
        }
        $validated = $request->validate([
            'title_fa' => ['sometimes', 'nullable', 'string', 'max:160'],
            'body_fa' => ['sometimes', 'required', 'string', 'min:2', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $templateText = implode(' ', array_filter([
            $validated['title_fa'] ?? '',
            $validated['body_fa'] ?? '',
        ]));
        if (preg_match('/latitude|longitude|coordinates?|password|passwd|token|secret|authorization|مختصات|رمز\s*ورود|رمز\s*عبور|نشانی|آدرس/iu', $templateText)) {
            return $this->error('UNSAFE_NOTIFICATION_TEMPLATE', 'قالب نمی‌تواند اطلاعات مکانی یا محرمانه را در اعلان درج کند.', 422);
        }
        preg_match_all('/\{\{([a-z_]+)\}\}/', $templateText, $matches);
        if (array_diff($matches[1], ['booking_code', 'service_name', 'status_label', 'scheduled_at', 'expires_at'])) {
            return $this->error('UNSUPPORTED_TEMPLATE_VARIABLE', 'قالب شامل متغیر پشتیبانی‌نشده است.', 422);
        }
        $exists = DB::table('notification_templates')
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->exists();
        if (! $exists) {
            return $this->error('TEMPLATE_NOT_FOUND', 'قالب اعلان پیدا نشد.', 404);
        }
        $reason = $validated['reason'] ?? null;
        unset($validated['reason']);
        DB::transaction(function () use ($request, $eventKey, $channel, $validated, $reason): void {
            $template = DB::table('notification_templates')
                ->where('event_key', $eventKey)->where('channel', $channel)->lockForUpdate()->first();
            if (! $template) {
                abort(404);
            }
            DB::table('notification_templates')
                ->where('event_key', $eventKey)
                ->where('channel', $channel)
                ->update($validated + ['updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'action' => 'notification.template.updated',
                'auditable_type' => 'notification_template',
                'auditable_id' => $template->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'old_values' => json_encode($template, JSON_THROW_ON_ERROR),
                'new_values' => json_encode($validated, JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['data' => ['updated' => true]]);
    }

    public function updateBookingSchedule(Request $request, int $bookingId, NotificationService $notifications): JsonResponse
    {
        if (! $this->can($request, 'notifications.manage')) {
            return $this->error('FORBIDDEN_NOTIFICATIONS', 'تغییر زمان و اعلان سفارش مجاز نیست.', 403);
        }
        $validated = $request->validate([
            'event' => ['required', Rule::in(['booking.delayed', 'booking.rescheduled'])],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $result = DB::transaction(function () use ($bookingId, $validated, $request, $notifications): array {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking) {
                return ['error' => 'BOOKING_NOT_FOUND'];
            }
            if (! in_array($booking->status, ['draft', 'broadcasting', 'assigned'], true)) {
                return ['error' => 'BOOKING_NOT_RESCHEDULABLE'];
            }
            $scheduledAt = Carbon::parse($validated['scheduled_at']);
            if ($booking->scheduled_at !== null && Carbon::parse($booking->scheduled_at)->equalTo($scheduledAt)) {
                return ['unchanged' => true, 'scheduled_at' => $booking->scheduled_at];
            }
            $oldScheduledAt = $booking->scheduled_at;
            DB::table('bookings')->where('id', $bookingId)->update([
                'scheduled_at' => $scheduledAt,
                'updated_at' => now(),
            ]);
            $changeId = DB::table('booking_schedule_changes')->insertGetId([
                'booking_id' => $bookingId,
                'event_key' => $validated['event'],
                'previous_scheduled_at' => $oldScheduledAt,
                'scheduled_at' => $scheduledAt,
                'changed_by_user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'action' => 'booking.schedule.changed',
                'auditable_type' => 'booking',
                'auditable_id' => $bookingId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'old_values' => json_encode(['scheduled_at' => $oldScheduledAt], JSON_THROW_ON_ERROR),
                'new_values' => json_encode([
                    'event' => $validated['event'],
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'reason' => $validated['reason'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $notifications->notifyBooking(
                $bookingId,
                $validated['event'],
                ['scheduled_at' => $scheduledAt->toIso8601String(), 'old_scheduled_at' => $oldScheduledAt],
                $validated['event'].':'.$bookingId.':'.$changeId,
            );

            return ['unchanged' => false, 'scheduled_at' => $scheduledAt->toIso8601String()];
        }, 3);
        if (isset($result['error'])) {
            return $this->error(
                $result['error'],
                $result['error'] === 'BOOKING_NOT_FOUND' ? 'سفارش پیدا نشد.' : 'در وضعیت فعلی تغییر زمان سفارش مجاز نیست.',
                $result['error'] === 'BOOKING_NOT_FOUND' ? 404 : 409,
            );
        }

        return response()->json(['data' => $result]);
    }

    private function can(Request $request, string $permission): bool
    {
        $roles = $permission === 'notifications.templates.manage'
            ? ['super_admin']
            : ['super_admin', 'operator', 'dispatcher'];

        return DB::table('permission_role')
            ->join('roles', 'permission_role.role_id', '=', 'roles.id')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->join('users', 'role_user.user_id', '=', 'users.id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $request->user()->id)
            ->where('users.status', 'active')
            ->whereIn('roles.name', $roles)
            ->where('permissions.name', $permission)
            ->exists();
    }

    private function isSupportedPushEndpoint(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (['fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'] as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function isBase64UrlKey(string $value, int $decodedLength): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return false;
        }
        $base64 = strtr($value, '-_', '+/');
        $decoded = base64_decode($base64.str_repeat('=', (4 - strlen($base64) % 4) % 4), true);

        return $decoded !== false && strlen($decoded) === $decodedLength;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $code, 'message' => $message], $status);
    }
}
