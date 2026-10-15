<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Middleware\RequireRole;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Health Endpoint
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

// Authentication & OTP Routes (Rate limited)
Route::prefix('auth')->middleware('throttle:30,1')->group(function () {
    Route::post('/otp/request', [AuthController::class, 'requestOtp']);
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
    Route::get('/me', [AuthController::class, 'me']);
});

// Protected Role-Based Routes
Route::prefix('technician')->middleware(RequireRole::class . ':technician')->group(function () {
    Route::get('/dashboard', function () {
        return response()->json([
            'success' => true,
            'message' => 'خوش آمدید، همکار گرامی کلیدساز.',
            'panel' => 'technician',
        ]);
    });
});

Route::prefix('admin')->middleware(RequireRole::class . ':super_admin,operator')->group(function () {
    Route::get('/operations/summary', function () {
        return response()->json([
            'success' => true,
            'message' => 'خلاصه وضعیت مانیتورینگ زنده شهر.',
            'panel' => 'operations',
        ]);
    });
});

Route::prefix('finance')->middleware(RequireRole::class . ':accountant,super_admin')->group(function () {
    Route::get('/ledger/summary', function () {
        return response()->json([
            'success' => true,
            'message' => 'خلاصه تراز دفتر کل مالی.',
            'panel' => 'finance',
        ]);
    });
});
