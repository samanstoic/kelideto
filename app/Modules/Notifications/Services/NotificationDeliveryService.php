<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Contracts\SmsGatewayInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class NotificationDeliveryService
{
    public function __construct(private readonly SmsGatewayInterface $smsGateway) {}

    public function deliver(int $notificationId): void
    {
        $notification = DB::transaction(function () use ($notificationId): ?object {
            $notification = DB::table('notifications_log')
                ->where('id', $notificationId)
                ->lockForUpdate()
                ->first();
            if (! $notification || in_array($notification->provider_status, ['accepted', 'suppressed', 'failed'], true)) {
                return null;
            }
            if ($notification->provider_status === 'sending' &&
                Carbon::parse($notification->updated_at)->greaterThan(now()->subMinute())) {
                return null;
            }
            DB::table('notifications_log')->where('id', $notificationId)->update([
                'attempts' => DB::raw('attempts + 1'),
                'provider_status' => 'sending',
                'status' => 'sending',
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
            $notification->attempts = (int) $notification->attempts + 1;

            return $notification;
        }, 3);
        if (! $notification) {
            return;
        }

        try {
            match ($notification->channel) {
                'in_app' => $this->deliverInApp($notification),
                'sms' => $this->deliverSms($notification),
                'web_push' => $this->deliverWebPush($notification),
                default => throw new RuntimeException('Unsupported notification channel.'),
            };
        } catch (\Throwable $exception) {
            $backoff = [30, 120, 600, 1800];
            $delay = $backoff[min((int) $notification->attempts, count($backoff) - 1)];
            DB::table('notifications_log')->where('id', $notificationId)->update([
                'provider_status' => 'retrying',
                'status' => 'retrying',
                'next_attempt_at' => now()->addSeconds($delay),
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    public function markFailed(int $notificationId, \Throwable $exception): void
    {
        DB::table('notifications_log')->where('id', $notificationId)->update([
            'provider_status' => 'failed',
            'delivery_status' => 'unknown',
            'status' => 'failed',
            'next_attempt_at' => null,
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            'updated_at' => now(),
        ]);
    }

    private function deliverInApp(object $notification): void
    {
        DB::table('notifications_log')->where('id', $notification->id)->update([
            'provider' => 'database',
            'provider_status' => 'accepted',
            'delivery_status' => 'delivered',
            'status' => 'sent',
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function deliverSms(object $notification): void
    {
        if (config('services.sms.driver') !== 'kavenegar' || ! filled(config('services.kavenegar.api_key'))) {
            throw new RuntimeException('SMS provider is disconnected; real credentials are not configured.');
        }
        if (! $notification->recipient || ! $this->smsGateway->sendNotification(
            (string) $notification->recipient,
            (string) $notification->body,
        )) {
            throw new RuntimeException('SMS provider did not accept the notification.');
        }
        DB::table('notifications_log')->where('id', $notification->id)->update([
            'provider' => 'kavenegar',
            'provider_status' => 'accepted',
            'delivery_status' => 'unknown',
            'status' => 'sent',
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function deliverWebPush(object $notification): void
    {
        $payload = json_decode((string) $notification->payload, true, flags: JSON_THROW_ON_ERROR);
        $subscription = DB::table('web_push_subscriptions')
            ->where('id', $payload['subscription_id'] ?? 0)
            ->where('user_id', $notification->user_id)
            ->first();
        if (! $subscription) {
            $this->markPermanentFailure((int) $notification->id, 'Consented Web Push subscription no longer exists.');

            return;
        }
        $publicKey = config('services.webpush.public_key');
        $privateKey = config('services.webpush.private_key');
        $subject = config('services.webpush.subject');
        if (! filled($publicKey) || ! filled($privateKey) || ! filled($subject)) {
            throw new RuntimeException('Web Push VAPID credentials are not configured.');
        }
        if (! class_exists(WebPush::class)) {
            throw new RuntimeException('The Web Push transport package is unavailable.');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]),
            json_encode([
                'title' => $payload['title'] ?? 'کلیدتو',
                'body' => $notification->body,
                'url' => $payload['url'] ?? '/orders?frontend=vanilla',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ['TTL' => 300],
        );
        if (! $report->isSuccess()) {
            if ($report->isSubscriptionExpired()) {
                DB::table('web_push_subscriptions')->where('id', $subscription->id)->delete();
                $this->markPermanentFailure((int) $notification->id, 'Web Push subscription expired.');

                return;
            }
            throw new RuntimeException('Web Push provider rejected the notification.');
        }
        DB::table('notifications_log')->where('id', $notification->id)->update([
            'provider' => 'vapid',
            'provider_status' => 'accepted',
            'delivery_status' => 'unknown',
            'status' => 'sent',
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function markPermanentFailure(int $notificationId, string $reason): void
    {
        DB::table('notifications_log')->where('id', $notificationId)->update([
            'provider_status' => 'failed',
            'delivery_status' => 'failed',
            'status' => 'failed',
            'next_attempt_at' => null,
            'last_error' => $reason,
            'updated_at' => now(),
        ]);
    }
}
