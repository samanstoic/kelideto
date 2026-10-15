<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

// Catch-all for SPA client-side routing
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
