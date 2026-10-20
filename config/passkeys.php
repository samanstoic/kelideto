<?php

$applicationUrl = (string) config('app.url');
$applicationHost = parse_url($applicationUrl, PHP_URL_HOST);
$configuredOrigins = env('PASSKEY_ALLOWED_ORIGINS');

return [
    'relying_party_id' => env('PASSKEY_RP_ID', $applicationHost),
    'explicitly_configured' => is_string(env('PASSKEY_RP_ID'))
        && trim((string) env('PASSKEY_RP_ID')) !== ''
        && is_string($configuredOrigins)
        && trim($configuredOrigins) !== ''
        && is_string(env('PASSKEYS_USER_HANDLE_SECRET'))
        && strlen((string) env('PASSKEYS_USER_HANDLE_SECRET')) >= 32,
    'allowed_origins' => is_string($configuredOrigins) && trim($configuredOrigins) !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $configuredOrigins))))
        : [$applicationUrl],
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET') ?: config('app.key'),
    'timeout' => 60000,
    'guard' => 'web',
    'middleware' => ['web'],
    'management_middleware' => [],
    'throttle' => 'throttle:6,1',
    'redirect' => '/',
];
