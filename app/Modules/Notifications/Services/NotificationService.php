<?php

namespace App\Modules\Notifications\Services;

use App\Jobs\DeliverNotificationJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class NotificationService
{
    private const EVENTS = [
        'booking.status_changed',
        'booking.cancelled',
        'booking.delayed',
        'booking.rescheduled',
        'dispatch.offer.issued',
    ];

    public function notifyBooking(int $bookingId, string $eventKey, array $data = [], ?string $eventId = null): void
    {
        if (! in_array($eventKey, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unsupported notification event.');
        }
        $booking = DB::table('bookings')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->join('customer_profiles', 'bookings.customer_id', '=', 'customer_profiles.id')
            ->where('bookings.id', $bookingId)
            ->first([
                'bookings.id',
                'bookings.booking_code',
                'bookings.status',
                'bookings.scheduled_at',
                'bookings.technician_id',
                'customer_profiles.user_id as customer_user_id',
                'services.title_fa as service_name',
            ]);
        if (! $booking) {
            return;
        }

        $statusLabels = [
            'draft' => 'ثبت‌شده',
            'broadcasting' => 'در حال جست‌وجوی متخصص',
            'assigned' => 'متخصص تخصیص یافت',
            'en_route' => 'متخصص در مسیر است',
            'arrived' => 'متخصص رسید',
            'in_progress' => 'خدمت در حال انجام است',
            'payment_pending' => 'در انتظار پرداخت',
            'completed' => 'تکمیل‌شده',
            'cancelled' => 'لغوشده',
            'disputed' => 'در حال بررسی اختلاف',
        ];
        $scheduledAt = $data['scheduled_at'] ?? $booking->scheduled_at;
        $safeData = [
            'booking_code' => (string) $booking->booking_code,
            'service_name' => (string) $booking->service_name,
            'status_label' => $statusLabels[$booking->status] ?? 'به‌روزرسانی شد',
            'scheduled_at' => $scheduledAt ? Carbon::parse($scheduledAt)->format('Y-m-d H:i') : '',
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->format('Y-m-d H:i') : '',
        ];

        $recipients = [(int) $booking->customer_user_id];
        if ($booking->technician_id !== null) {
            $technicianUserId = DB::table('technician_profiles')
                ->where('id', $booking->technician_id)
                ->value('user_id');
            if ($technicianUserId) {
                $recipients[] = (int) $technicianUserId;
            }
        }
        if ($eventKey === 'dispatch.offer.issued') {
            $recipients = array_filter([
                DB::table('dispatch_offers')
                    ->join('technician_profiles', 'dispatch_offers.technician_id', '=', 'technician_profiles.id')
                    ->where('dispatch_offers.booking_id', $bookingId)
                    ->where('dispatch_offers.expires_at', '>', now())
                    ->orderByDesc('dispatch_offers.id')
                    ->value('technician_profiles.user_id'),
            ]);
        }

        $this->notifyUsers(
            array_values(array_unique(array_map('intval', $recipients))),
            $eventKey,
            $safeData,
            $eventId ?? $eventKey.':'.$bookingId.':'.now()->format('YmdHis.u'),
        );
    }

    public function notifyTechnicianOffer(int $offerId): void
    {
        $offer = DB::table('dispatch_offers')
            ->join('technician_profiles', 'dispatch_offers.technician_id', '=', 'technician_profiles.id')
            ->where('dispatch_offers.id', $offerId)
            ->join('bookings', 'dispatch_offers.booking_id', '=', 'bookings.id')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->where('bookings.status', 'broadcasting')
            ->first([
                'dispatch_offers.booking_id',
                'dispatch_offers.expires_at',
                'technician_profiles.user_id',
                'bookings.booking_code',
                'services.title_fa as service_name',
            ]);
        if (! $offer) {
            return;
        }
        $this->notifyUsers(
            [(int) $offer->user_id],
            'dispatch.offer.issued',
            [
                'booking_code' => (string) $offer->booking_code,
                'service_name' => (string) $offer->service_name,
                'status_label' => '',
                'scheduled_at' => '',
                'expires_at' => Carbon::parse($offer->expires_at)->format('Y-m-d H:i'),
            ],
            'dispatch.offer.issued:'.$offerId,
        );
    }

    public function notifyUsers(array $userIds, string $eventKey, array $data, string $eventId): void
    {
        if (! in_array($eventKey, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unsupported notification event.');
        }
        DB::transaction(function () use ($userIds, $eventKey, $data, $eventId): void {
            foreach ($userIds as $userId) {
                $user = DB::table('users')->where('id', $userId)->where('status', 'active')->lockForUpdate()->first();
                if (! $user) {
                    continue;
                }
                foreach (['in_app', 'sms'] as $channel) {
                    $this->queueForChannel((int) $user->id, $user->phone_number, $eventKey, $channel, $data, $eventId);
                }
                $subscriptions = DB::table('web_push_subscriptions')
                    ->where('user_id', $user->id)
                    ->whereNotNull('consented_at')
                    ->get(['id', 'endpoint_hash']);
                foreach ($subscriptions as $subscription) {
                    $this->queueForChannel(
                        (int) $user->id,
                        'webpush:'.$subscription->endpoint_hash,
                        $eventKey,
                        'web_push',
                        $data + ['subscription_id' => (int) $subscription->id],
                        $eventId,
                        (int) $subscription->id,
                    );
                }
            }
        }, 3);
    }

    public function serviceStatus(): array
    {
        $smsDriver = (string) config('services.sms.driver', 'mock');
        $smsConfigured = $smsDriver === 'kavenegar' && filled(config('services.kavenegar.api_key'));
        $pushConfigured = false;
        if (filled(config('services.webpush.public_key')) &&
            filled(config('services.webpush.private_key')) &&
            filled(config('services.webpush.subject')) &&
            class_exists(WebPush::class)) {
            try {
                VAPID::validate([
                    'subject' => config('services.webpush.subject'),
                    'publicKey' => config('services.webpush.public_key'),
                    'privateKey' => config('services.webpush.private_key'),
                ]);
                $pushConfigured = true;
            } catch (\ErrorException) {
                $pushConfigured = false;
            }
        }

        return [
            'in_app' => ['status' => 'connected', 'provider' => 'database'],
            'sms' => [
                'status' => $smsConfigured ? 'connected' : 'disconnected',
                'provider' => $smsDriver,
                'mode' => $smsDriver === 'mock' ? 'simulation' : 'live',
            ],
            'web_push' => [
                'status' => $pushConfigured ? 'connected' : 'disconnected',
                'provider' => 'vapid',
            ],
        ];
    }

    private function queueForChannel(
        int $userId,
        ?string $phone,
        string $eventKey,
        string $channel,
        array $data,
        string $eventId,
        ?int $subscriptionId = null,
    ): void {
        $preference = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->first();
        $defaultEnabled = $channel !== 'web_push' || $subscriptionId !== null;
        if ($channel === 'sms' && ! $phone) {
            return;
        }
        if ($channel === 'web_push' && $subscriptionId === null) {
            return;
        }
        if ($preference && ! $preference->enabled) {
            return;
        }

        $hourlyLimit = (int) ($preference->hourly_limit ?? 10);
        $recentCount = DB::table('notifications_log')
            ->where('user_id', $userId)
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        $template = DB::table('notification_templates')
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->first();
        if (! $template || (! $preference && ! $defaultEnabled)) {
            return;
        }

        $body = $this->render((string) $template->body_fa, $data);
        $title = $template->title_fa === null ? null : $this->render((string) $template->title_fa, $data);
        $payload = [
            'title' => $title,
            'body' => $body,
            'url' => '/orders?frontend=vanilla',
        ];
        if ($subscriptionId !== null) {
            $payload['subscription_id'] = $subscriptionId;
        }
        $key = hash('sha256', implode(':', [
            $eventId,
            $userId,
            $channel,
            (string) ($subscriptionId ?? ''),
        ]));
        $now = now();
        $suppressed = $recentCount >= $hourlyLimit;
        $providerStatus = $suppressed ? 'suppressed' : ($channel === 'in_app' ? 'accepted' : 'queued');
        $deliveryStatus = $channel === 'in_app' && ! $suppressed ? 'delivered' : 'unknown';
        $logId = DB::table('notifications_log')->insertOrIgnore([
            'user_id' => $userId,
            'channel' => $channel,
            'recipient' => $channel === 'sms' ? (string) $phone : (string) $userId,
            'event_key' => $eventKey,
            'idempotency_key' => $key,
            'template_key' => $eventKey,
            'body' => $body,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'provider' => $channel === 'in_app'
                ? 'database'
                : ($channel === 'sms' ? (string) config('services.sms.driver', 'mock') : $channel),
            'status' => $suppressed ? 'suppressed' : ($channel === 'in_app' ? 'sent' : 'queued'),
            'provider_status' => $providerStatus,
            'delivery_status' => $deliveryStatus,
            'last_error' => $suppressed ? 'Hourly notification limit reached.' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($logId !== 1) {
            return;
        }
        if ($suppressed || $channel === 'in_app') {
            return;
        }
        $notificationId = (int) DB::table('notifications_log')->where('idempotency_key', $key)->value('id');
        DeliverNotificationJob::dispatch($notificationId)->afterCommit();
    }

    private function render(string $template, array $data): string
    {
        return trim(preg_replace_callback(
            '/\{\{([a-z_]+)\}\}/',
            static fn (array $match): string => (string) ($data[$match[1]] ?? ''),
            strip_tags($template),
        ) ?? '');
    }
}
