<?php

use App\Modules\Booking\Http\Controllers\AdditionalCostController;
use App\Modules\Booking\Http\Controllers\BookingAuthorizationController;
use App\Modules\Booking\Http\Controllers\BookingController;
use App\Modules\Booking\Http\Controllers\BookingLocationController;
use App\Modules\Booking\Http\Controllers\DispatchController;
use App\Modules\Booking\Http\Controllers\LocationSearchController;
use App\Modules\Booking\Http\Controllers\OfflineDraftSyncController;
use App\Modules\Booking\Http\Controllers\PricingController;
use App\Modules\Booking\Http\Controllers\ServiceCatalogController;
use App\Modules\Booking\Http\Controllers\TechnicianController;
use App\Modules\FinancialLedger\Http\Controllers\FinanceController;
use App\Modules\Identity\Http\Controllers\AdminConsoleController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\ContentAdminController;
use App\Modules\Identity\Http\Controllers\InitialSetupController;
use App\Modules\Identity\Http\Middleware\RequireRole;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Payment\Http\Controllers\PaymentController;
use App\Modules\Support\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Health Endpoint
Route::get('/health', function () {
    $dbStatus = 'healthy';

    try {
        DB::connection()->getPdo();
    } catch (Throwable $exception) {
        report($exception);
        $dbStatus = 'unhealthy';
    }

    $cacheStatus = 'healthy';
    try {
        Cache::put('health_check_key', 'ok', 10);
        $val = Cache::get('health_check_key');
        if ($val !== 'ok') {
            $cacheStatus = 'unhealthy: cache read mismatch';
        }
    } catch (Throwable $exception) {
        report($exception);
        $cacheStatus = 'unhealthy';
    }

    $overall = ($dbStatus === 'healthy' && $cacheStatus === 'healthy') ? 'healthy' : 'degraded';

    return response()->json([
        'status' => $overall,
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'database' => ['status' => $dbStatus],
            'cache' => ['status' => $cacheStatus],
        ],
    ], $overall === 'healthy' ? 200 : 503);
});

// Authentication & OTP Routes (Rate limited)
Route::prefix('auth')->middleware('throttle:30,1')->group(function () {
    Route::post('/otp/request', [AuthController::class, 'requestOtp']);
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::prefix('setup')->group(function () {
    Route::get('/status', [InitialSetupController::class, 'status'])->middleware('throttle:30,1');
    Route::post('/complete', [InitialSetupController::class, 'complete'])->middleware('throttle:3,1');
});
Route::get('/technicians/{technicianId}/quality', [SupportController::class, 'publicQuality'])
    ->whereNumber('technicianId')->middleware('throttle:60,1');

Route::get('/services', [ServiceCatalogController::class, 'index']);
Route::get('/payments/{paymentUuid}/callback', [PaymentController::class, 'callback'])
    ->whereUuid('paymentUuid')
    ->middleware('throttle:30,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/bookings/{bookingId}/payments', [PaymentController::class, 'initiate'])
        ->whereNumber('bookingId')
        ->middleware('throttle:10,1');
    Route::get('/payments/{paymentUuid}', [PaymentController::class, 'show'])->whereUuid('paymentUuid');
    Route::get('/wallet', [PaymentController::class, 'wallet']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notificationId');
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
    Route::put('/notifications/preferences', [NotificationController::class, 'updatePreference']);
    Route::get('/notifications/web-push/public-key', [NotificationController::class, 'webPushPublicKey']);
    Route::post('/notifications/web-push-subscriptions', [NotificationController::class, 'storeWebPushSubscription'])
        ->middleware('throttle:10,1');
    Route::delete('/notifications/web-push-subscriptions', [NotificationController::class, 'deleteWebPushSubscription'])
        ->middleware('throttle:10,1');
    Route::post('/bookings/draft-sync', [OfflineDraftSyncController::class, 'store']);
    Route::post('/location/search', LocationSearchController::class);
    Route::get('/bookings/{bookingId}/customer-location', [BookingLocationController::class, 'customerLocation'])
        ->whereNumber('bookingId');
    Route::post('/bookings/{bookingId}/customer-authority/verify', [BookingAuthorizationController::class, 'verify'])
        ->whereNumber('bookingId')
        ->middleware('throttle:20,1');
    Route::post('/pricing/estimates', [PricingController::class, 'estimate'])->middleware('throttle:30,1');
    Route::post('/bookings', [PricingController::class, 'createBooking'])->middleware('throttle:20,1');
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{bookingCode}', [BookingController::class, 'show'])->whereUuid('bookingCode');
    Route::post('/bookings/{bookingCode}/cancel', [BookingController::class, 'cancel'])
        ->whereUuid('bookingCode')
        ->middleware('throttle:20,1');
    Route::get('/bookings/{bookingId}/additional-costs', [AdditionalCostController::class, 'index'])->whereNumber('bookingId');
    Route::post('/bookings/{bookingId}/additional-costs', [AdditionalCostController::class, 'store'])
        ->whereNumber('bookingId')
        ->middleware('throttle:20,1');
    Route::post('/bookings/{bookingId}/additional-costs/{costId}/respond', [AdditionalCostController::class, 'respond'])
        ->whereNumber('bookingId')
        ->whereNumber('costId')
        ->middleware('throttle:20,1');
    Route::post('/technician/register', [TechnicianController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/bookings/{bookingId}/reviews', [SupportController::class, 'createReview'])
        ->whereNumber('bookingId')->middleware('throttle:5,1');
    Route::get('/support/tickets', [SupportController::class, 'tickets']);
    Route::get('/support/bookings', [SupportController::class, 'relatedBookings']);
    Route::post('/support/tickets', [SupportController::class, 'createTicket'])->middleware('throttle:10,1');
    Route::get('/support/tickets/{ticketCode}', [SupportController::class, 'messages'])
        ->whereUuid('ticketCode');
    Route::post('/support/tickets/{ticketCode}/messages', [SupportController::class, 'reply'])
        ->whereUuid('ticketCode')->middleware('throttle:20,1');
    Route::get('/support/tickets/{ticketCode}/attachments/{attachmentId}', [SupportController::class, 'attachment'])
        ->whereUuid('ticketCode')->whereNumber('attachmentId')->middleware('throttle:30,1');

    Route::prefix('admin')->group(function () {
        Route::get('/support/tickets', [SupportController::class, 'adminTickets']);
        Route::patch('/support/tickets/{ticketCode}', [SupportController::class, 'updateTicket'])
            ->whereUuid('ticketCode');
        Route::post('/support/tickets/{ticketCode}/decision', [SupportController::class, 'decide'])
            ->whereUuid('ticketCode');
        Route::get('/support/reviews', [SupportController::class, 'moderateReviews']);
        Route::patch('/support/reviews/{reviewId}', [SupportController::class, 'moderateReview'])
            ->whereNumber('reviewId');
        Route::get('/support/quality', [SupportController::class, 'qualityReport']);
        Route::get('/console/dashboard', [AdminConsoleController::class, 'dashboard']);
        Route::get('/console/users', [AdminConsoleController::class, 'users']);
        Route::patch('/console/users/{userId}', [AdminConsoleController::class, 'updateUser'])->whereNumber('userId');
        Route::get('/console/roles', [AdminConsoleController::class, 'roles']);
        Route::get('/console/technicians', [AdminConsoleController::class, 'technicians']);
        Route::patch('/console/technicians/{technicianId}', [AdminConsoleController::class, 'updateTechnician'])->whereNumber('technicianId');
        Route::get('/console/bookings', [AdminConsoleController::class, 'bookings']);
        Route::get('/console/operations-map', [AdminConsoleController::class, 'operationsMap']);
        Route::get('/console/resources/{resource}', [AdminConsoleController::class, 'resourceIndex']);
        Route::post('/console/resources/{resource}', [AdminConsoleController::class, 'storeResource']);
        Route::get('/console/content/media', [ContentAdminController::class, 'mediaIndex']);
        Route::post('/console/content/preview', [ContentAdminController::class, 'preview']);
        Route::post('/console/content/media', [ContentAdminController::class, 'uploadMedia']);
        Route::get('/console/content/redirects', [ContentAdminController::class, 'redirects']);
        Route::post('/console/content/redirects', [ContentAdminController::class, 'storeRedirect']);
        Route::patch('/console/content/redirects/{id}', [ContentAdminController::class, 'updateRedirect'])
            ->whereNumber('id');
        Route::delete('/console/content/redirects/{id}', [ContentAdminController::class, 'deleteRedirect'])
            ->whereNumber('id');
        Route::patch('/console/resources/{resource}/{id}', [AdminConsoleController::class, 'updateResource'])
            ->whereNumber('id');
        Route::delete('/console/content/{id}', [AdminConsoleController::class, 'deleteContent'])->whereNumber('id');
        Route::get('/console/tickets/{ticketId}/messages', [AdminConsoleController::class, 'ticketMessages'])->whereNumber('ticketId');
        Route::patch('/console/tickets/{ticketId}', [AdminConsoleController::class, 'updateTicket'])->whereNumber('ticketId');
        Route::post('/console/tickets', [AdminConsoleController::class, 'createTicket']);
        Route::get('/console/audit-logs', [AdminConsoleController::class, 'auditLogs']);
        Route::get('/console/reports', [AdminConsoleController::class, 'reports']);
        Route::get('/console/payments', [AdminConsoleController::class, 'payments']);
        Route::post('/console/payments/{paymentId}/refund', [AdminConsoleController::class, 'refundPayment'])
            ->whereNumber('paymentId');
        Route::post('/console/settlements/{settlementUuid}/review', [AdminConsoleController::class, 'reviewSettlement'])
            ->whereUuid('settlementUuid');
        Route::get('/console/access/roles', [AdminConsoleController::class, 'accessRoles']);
        Route::post('/console/access/roles', [AdminConsoleController::class, 'createAccessRole']);
        Route::patch('/console/access/roles/{roleId}', [AdminConsoleController::class, 'updateAccessRole'])->whereNumber('roleId');
        Route::get('/console/access/policies', [AdminConsoleController::class, 'securityPolicies']);
        Route::put('/console/access/policies/{key}', [AdminConsoleController::class, 'saveSecurityPolicy'])
            ->where('key', '[a-z][a-z0-9_.-]{1,119}');
        Route::get('/console/reports/export', [AdminConsoleController::class, 'exportReports']);

        Route::get('/technicians/documents/pending', [TechnicianController::class, 'pendingReviews']);
        Route::get('/technicians/{technicianId}/documents/{documentId}', [TechnicianController::class, 'documentFile'])
            ->whereNumber('technicianId')
            ->whereNumber('documentId');
        Route::post('/technicians/{technicianId}/documents/{documentId}/review', [TechnicianController::class, 'reviewDocument'])
            ->whereNumber('technicianId')
            ->whereNumber('documentId');
        Route::post('/bookings/{bookingId}/dispatch', [DispatchController::class, 'issueOffer'])
            ->whereNumber('bookingId')
            ->middleware('throttle:20,1');
        Route::post('/bookings/{bookingId}/dispatch-offers', [DispatchController::class, 'issueOffer'])
            ->whereNumber('bookingId')
            ->middleware('throttle:20,1');
        Route::post('/bookings/{bookingId}/assign', [DispatchController::class, 'assignManually'])
            ->whereNumber('bookingId')
            ->middleware('throttle:20,1');
        Route::post('/bookings/{bookingId}/schedule', [NotificationController::class, 'updateBookingSchedule'])
            ->whereNumber('bookingId')
            ->middleware('throttle:20,1');
        Route::get('/notifications/services', [NotificationController::class, 'services']);
        Route::get('/notifications/templates', [NotificationController::class, 'templates']);
        Route::patch('/notifications/templates/{eventKey}/{channel}', [NotificationController::class, 'updateTemplate']);
        Route::get('/bookings/{bookingId}/dispatch-events', [DispatchController::class, 'events'])
            ->whereNumber('bookingId');
        Route::get('/catalog/categories', [ServiceCatalogController::class, 'manageCategories']);
        Route::post('/catalog/categories', [ServiceCatalogController::class, 'storeCategory']);
        Route::patch('/catalog/categories/{categoryId}', [ServiceCatalogController::class, 'updateCategory'])
            ->whereNumber('categoryId');
        Route::get('/catalog/services', [ServiceCatalogController::class, 'manageServices']);
        Route::post('/catalog/services', [ServiceCatalogController::class, 'storeService']);
        Route::patch('/catalog/services/{serviceId}', [ServiceCatalogController::class, 'updateService'])
            ->whereNumber('serviceId');
        Route::get('/service-areas', [ServiceCatalogController::class, 'manageAreas']);
        Route::post('/service-areas', [ServiceCatalogController::class, 'storeArea']);
        Route::patch('/service-areas/{areaId}', [ServiceCatalogController::class, 'updateArea'])
            ->whereNumber('areaId');
        Route::get('/pricing/tariffs', [ServiceCatalogController::class, 'listTariffs']);
        Route::post('/pricing/tariffs', [ServiceCatalogController::class, 'storeTariff']);
    });
});

// Protected Role-Based Routes
Route::prefix('technician')->middleware(['auth:sanctum', RequireRole::class.':technician'])->group(function () {
    Route::get('/dashboard', [TechnicianController::class, 'dashboard']);
    Route::put('/profile', [TechnicianController::class, 'profile']);
    Route::post('/documents', [TechnicianController::class, 'uploadDocument'])->middleware('throttle:10,1');
    Route::put('/availability', [TechnicianController::class, 'availability']);
    Route::get('/offers', [TechnicianController::class, 'offers']);
    Route::post('/offers/{offerId}/respond', [DispatchController::class, 'respondToOffer'])
        ->whereNumber('offerId')
        ->middleware('throttle:30,1');
    Route::get('/missions', [TechnicianController::class, 'missions']);
    Route::get('/missions/{bookingCode}', [TechnicianController::class, 'mission'])->whereUuid('bookingCode');
    Route::post('/missions/{bookingCode}/status', [TechnicianController::class, 'updateMissionStatus'])
        ->whereUuid('bookingCode')
        ->middleware('throttle:30,1');
    Route::post('/missions/{bookingCode}/evidence', [TechnicianController::class, 'uploadEvidence'])
        ->whereUuid('bookingCode')
        ->middleware('throttle:10,1');
    Route::get('/earnings', [TechnicianController::class, 'earnings']);
    Route::post('/settlements', [PaymentController::class, 'requestSettlement'])->middleware('throttle:5,1');
});

Route::prefix('admin')->middleware(['auth:sanctum', RequireRole::class.':super_admin,operator'])->group(function () {
    Route::get('/operations/summary', [AdminConsoleController::class, 'dashboard']);
});

Route::prefix('finance')->middleware('auth:sanctum')->group(function () {
    Route::middleware(RequireRole::class.':accountant,super_admin')->group(function () {
        Route::get('/gateway', [FinanceController::class, 'gatewayStatus']);
        Route::get('/ledger', [FinanceController::class, 'ledger']);
        Route::get('/ledger/summary', [FinanceController::class, 'summary']);
        Route::get('/reports/summary', [FinanceController::class, 'summary']);
        Route::get('/settlements', [FinanceController::class, 'settlements']);
        Route::get('/settlements/{settlementUuid}/events', [FinanceController::class, 'settlementEvents'])
            ->whereUuid('settlementUuid');
        Route::post('/settlements/{settlementUuid}/review', [FinanceController::class, 'reviewSettlement'])
            ->whereUuid('settlementUuid');
        Route::post('/payments/{paymentId}/refund', [FinanceController::class, 'refund'])->whereNumber('paymentId');
        Route::post('/reconciliation', [FinanceController::class, 'reconcile']);
    });
});
