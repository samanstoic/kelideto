<?php

use App\Modules\Booking\Services\DispatchService;
use App\Modules\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dispatch:expire-offers', function () {
    $expired = app(DispatchService::class)->expireDueOffers();
    $this->info("Expired {$expired} dispatch offers.");
})->purpose('Expire unanswered dispatch offers and notify the next eligible technician');

Artisan::command('offline-drafts:purge-expired', function () {
    $deleted = DB::table('offline_sync_records')
        ->where('expires_at', '<=', now())
        ->delete();
    $this->info("Purged {$deleted} expired offline draft sync records.");
})->purpose('Delete offline draft sync payloads after their seven-day retention period');

Artisan::command('technicians:purge-stale-telemetry', function () {
    $cutoff = now()->subMinutes(15);
    $profiles = DB::table('technician_profiles')
        ->whereNotNull('last_telemetry_at')
        ->where('last_telemetry_at', '<=', $cutoff)
        ->where(function ($query): void {
            $query->whereNotNull('current_lat')->orWhereNotNull('current_lng');
        })
        ->select('id')
        ->get();

    foreach ($profiles as $profile) {
        DB::table('technician_profiles')->where('id', $profile->id)->update([
            'is_online' => false,
            'is_available' => false,
            'current_lat' => null,
            'current_lng' => null,
            'last_telemetry_at' => null,
            'updated_at' => now(),
        ]);
    }

    if ($profiles->isNotEmpty()) {
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'technician.telemetry.expired',
            'auditable_type' => 'technician_profiles',
            'ip_address' => null,
            'user_agent' => 'scheduler',
            'new_values' => json_encode([
                'profiles_cleared' => $profiles->count(),
                'cutoff' => $cutoff->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->info("Cleared stale telemetry for {$profiles->count()} technician profiles.");
})->purpose('Clear technician coordinates after the dispatch freshness window');

Artisan::command('content:publish-scheduled', function () {
    $published = 0;
    DB::table('content_pages')
        ->where('status', 'draft')
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->chunkById(100, function ($pages) use (&$published): void {
            foreach ($pages as $page) {
                DB::transaction(function () use ($page, &$published): void {
                    $locked = DB::table('content_pages')->where('id', $page->id)
                        ->where('status', 'draft')->where('scheduled_at', '<=', now())
                        ->whereNull('deleted_at')->lockForUpdate()->first();
                    if (! $locked) {
                        return;
                    }
                    DB::table('content_pages')->where('id', $page->id)->update([
                        'status' => 'published',
                        'published_at' => now(),
                        'scheduled_at' => null,
                        'updated_at' => now(),
                    ]);
                    DB::table('audit_logs')->insert([
                        'user_id' => null,
                        'action' => 'admin.content.scheduled_published',
                        'auditable_type' => 'content_page',
                        'auditable_id' => $page->id,
                        'old_values' => json_encode(['status' => 'draft', 'scheduled_at' => $locked->scheduled_at], JSON_THROW_ON_ERROR),
                        'new_values' => json_encode(['status' => 'published', 'published_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR),
                        'reason' => 'انتشار زمان‌بندی‌شده توسط سامانه',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $published++;
                });
            }
        });
    $this->info("Published {$published} scheduled content pages.");
})->purpose('Publish content pages whose scheduled publication time has arrived');

Artisan::command('finance:verify-gateway {authority} {amount_minor}', function (
    PaymentGatewayInterface $gateway,
    string $authority,
    int $amount_minor,
) {
    if ($amount_minor <= 0) {
        $this->error('Amount must be a positive integer number of rials.');

        return 1;
    }
    $result = $gateway->verify($authority, $amount_minor);
    DB::table('gateway_health_checks')->insert([
        'gateway_name' => $gateway->name(),
        'check_type' => 'manual_verification',
        'provider_reference_hash' => hash('sha256', (string) ($result['reference'] ?? $authority)),
        'passed' => (bool) ($result['verified'] ?? false),
        'result_code' => (string) ($result['code'] ?? $result['error'] ?? 'unknown'),
        'checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    if (! ($result['verified'] ?? false)) {
        $this->error('Gateway verification failed. The gateway remains disabled.');

        return 1;
    }
    $this->info('Gateway verification succeeded; the adapter may now accept payment requests.');

    return 0;
})->purpose('Verify an existing provider transaction before enabling the payment gateway');

Schedule::command('dispatch:expire-offers')->everyMinute()->withoutOverlapping();
Schedule::command('offline-drafts:purge-expired')->hourly()->withoutOverlapping();
Schedule::command('technicians:purge-stale-telemetry')->everyMinute()->withoutOverlapping();
Schedule::command('content:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
