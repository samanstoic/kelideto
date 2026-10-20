<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LocationSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:250'],
        ]);

        $searchUrl = config('services.nominatim.search_url');
        if (!is_string($searchUrl) || filter_var($searchUrl, FILTER_VALIDATE_URL) === false ||
            parse_url($searchUrl, PHP_URL_SCHEME) !== 'https') {
            return response()->json([
                'success' => false,
                'error' => 'GEOCODING_NOT_CONFIGURED',
                'message' => 'سرویس جست‌وجوی نشانی پیکربندی نشده است؛ مختصات را دستی وارد کنید.',
            ], 503);
        }

        $cacheKey = 'keypro:geocoding:result:' .
            hash('sha256', $searchUrl . "\n" . Str::lower(trim($validated['query'])));
        $cachedResult = Cache::get($cacheKey);
        if (is_array($cachedResult)) {
            return response()->json(['success' => true, 'data' => $cachedResult]);
        }

        if (!Cache::add('keypro:geocoding:global-rate-limit', true, now()->addSecond())) {
            return response()->json([
                'success' => false,
                'error' => 'GEOCODING_RATE_LIMITED',
                'message' => 'جست‌وجوی نشانی موقتاً محدود است؛ یک ثانیه بعد دوباره تلاش کنید.',
            ], 429)->header('Retry-After', '1');
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => sprintf('KeyPro/1.0 (+%s)', config('app.url')),
                'Referer' => config('app.url'),
            ])->acceptJson()->timeout(8)->get($searchUrl, [
                'format' => 'jsonv2',
                'limit' => 1,
                'q' => $validated['query'],
            ]);
        } catch (ConnectionException) {
            return response()->json([
                'success' => false,
                'error' => 'GEOCODING_UNAVAILABLE',
                'message' => 'سرویس جست‌وجوی نشانی در دسترس نیست؛ مختصات را دستی وارد کنید.',
            ], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'error' => 'GEOCODING_UPSTREAM_ERROR',
                'message' => 'سرویس جست‌وجوی نشانی پاسخ معتبر نداد.',
            ], 502);
        }

        $results = $response->json();
        if (!is_array($results)) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_GEOCODING_RESPONSE',
                'message' => 'پاسخ سرویس جست‌وجوی نشانی معتبر نیست.',
            ], 502);
        }
        $result = $results[0] ?? null;
        if ($result === null) {
            return response()->json([
                'success' => false,
                'error' => 'LOCATION_NOT_FOUND',
                'message' => 'برای این نشانی نتیجه‌ای پیدا نشد؛ مختصات را دستی وارد کنید.',
            ], 404);
        }

        $latitude = filter_var($result['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($result['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false ||
            $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ||
            !is_string($result['display_name'] ?? null)) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_GEOCODING_RESULT',
                'message' => 'مختصات برگشتی سرویس جست‌وجو معتبر نیست.',
            ], 502);
        }

        $data = [
            'lat' => round($latitude, 7),
            'lng' => round($longitude, 7),
            'address' => $result['display_name'],
        ];
        Cache::put($cacheKey, $data, now()->addDays(30));

        return response()->json(['success' => true, 'data' => $data]);
    }
}
