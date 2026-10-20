<?php

namespace App\Modules\Identity\Adapters;

use App\Modules\Identity\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Log;

class MockSmsGatewayAdapter implements SmsGatewayInterface
{
    public function sendOtp(string $phoneNumber, string $code): bool
    {
        if (config('app.env') === 'production') {
            Log::error('Mock SMS gateway is disabled in production.');

            return false;
        }

        Log::info('Mock SMS OTP delivery simulated.');

        return true;
    }

    public function sendNotification(string $phoneNumber, string $message): bool
    {
        if (config('app.env') === 'production') {
            Log::error('Mock SMS gateway is disabled in production.');

            return false;
        }

        Log::info('SMS notification simulated by the mock adapter.');

        return true;
    }
}
