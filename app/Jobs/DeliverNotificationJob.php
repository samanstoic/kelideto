<?php

namespace App\Jobs;

use App\Modules\Notifications\Services\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $notificationId)
    {
        $this->afterCommit = true;
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(NotificationDeliveryService $delivery): void
    {
        $delivery->deliver($this->notificationId);
    }

    public function failed(Throwable $exception): void
    {
        app(NotificationDeliveryService::class)->markFailed($this->notificationId, $exception);
    }
}
