<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Identity\Http\Controllers\AdminPasskeyController;
use App\Modules\Identity\Http\Controllers\PublicContentController;

Route::prefix('api/auth/admin')->group(function () {
    Route::post('/login', [AdminPasskeyController::class, 'begin'])
        ->middleware('throttle:5,1')->block(10, 10);
    Route::prefix('passkeys')->middleware('throttle:10,1')->group(function () {
        Route::get('/options', [AdminPasskeyController::class, 'options'])->block(10, 10);
        Route::post('/register', [AdminPasskeyController::class, 'register'])->block(10, 10);
        Route::post('/verify', [AdminPasskeyController::class, 'verify'])->block(10, 10);
    });
});

Route::get('/', function () {
    return view('app');
});

Route::get('/sitemap.xml', [PublicContentController::class, 'sitemap']);
Route::get('/robots.txt', [PublicContentController::class, 'robots']);
Route::get('/content-media/{publicId}', [PublicContentController::class, 'media'])
    ->whereUuid('publicId');
Route::get('/services', [PublicContentController::class, 'services']);
Route::get('/services/{slug}', [PublicContentController::class, 'service'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::get('/areas', [PublicContentController::class, 'areas']);
Route::get('/areas/{slug}', [PublicContentController::class, 'area'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::get('/pages/{slug}', [PublicContentController::class, 'page'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::get('/articles/{slug}', [PublicContentController::class, 'article'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');

// Catch-all for SPA client-side routing
Route::get('/{any}', [PublicContentController::class, 'redirectOrSpa'])
    ->where('any', '^(?!api(?:/|$)|content-media(?:/|$)|services(?:/|$)|areas(?:/|$)|pages(?:/|$)|articles(?:/|$)|sitemap\.xml$|robots\.txt$).*$');
