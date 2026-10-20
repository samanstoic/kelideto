<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ApplyRuntimeAppSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/setup/*') || $request->is('api/health') || $request->is('setup')) {
            return $next($request);
        }

        if (Schema::hasTable('app_settings')) {
            $settings = DB::table('app_settings')
                ->whereIn('setting_key', ['site.name', 'app.timezone', 'app.locale', 'dispatch.response_window_seconds'])
                ->pluck('setting_value', 'setting_key');

            foreach ([
                'site.name' => 'app.name',
                'app.timezone' => 'app.timezone',
                'app.locale' => 'app.locale',
                'dispatch.response_window_seconds' => 'keypro.dispatch.response_window_seconds',
            ] as $settingKey => $configKey) {
                if (isset($settings[$settingKey])) {
                    config([$configKey => json_decode($settings[$settingKey], true, 512, JSON_THROW_ON_ERROR)]);
                }
            }
            date_default_timezone_set((string) config('app.timezone'));
        }

        return $next($request);
    }
}
