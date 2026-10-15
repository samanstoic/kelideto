<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $dbStatus = 'healthy';
    $dbDriver = DB::getDriverName();
    
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $dbStatus = 'unhealthy: ' . $e->getMessage();
    }

    $cacheStatus = 'healthy';
    try {
        Cache::put('health_check_key', 'ok', 10);
        $val = Cache::get('health_check_key');
        if ($val !== 'ok') {
            $cacheStatus = 'unhealthy: cache read mismatch';
        }
    } catch (\Throwable $e) {
        $cacheStatus = 'unhealthy: ' . $e->getMessage();
    }

    $overall = ($dbStatus === 'healthy' && $cacheStatus === 'healthy') ? 'healthy' : 'degraded';

    return response()->json([
        'status' => $overall,
        'app' => config('app.name'),
        'environment' => app()->environment(),
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'database' => [
                'status' => $dbStatus,
                'driver' => $dbDriver,
            ],
            'cache' => [
                'status' => $cacheStatus,
            ],
        ],
    ], $overall === 'healthy' ? 200 : 503);
});
