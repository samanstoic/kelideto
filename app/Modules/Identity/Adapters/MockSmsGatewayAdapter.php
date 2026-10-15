<?php

namespace App\Modules\Identity\Adapters;

use App\Modules\Identity\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MockSmsGatewayAdapter implements SmsGatewayInterface
{
    public function sendOtp(string $phoneNumber, string $code): bool
    {
        Log::info("[SMS Mock Adapter] OTP sent to {$phoneNumber}: {$code}");

        // Store notification record in notifications_log table
        DB::table('notifications_log')->insert([
            'user_id' => null,
            'channel' => 'sms',
            'recipient' => $phoneNumber,
            'template_key' => 'auth_otp',
            'body' => "کد تایید ورود به سامانه کلیدتو: {$code}",
            'provider' => 'mock',
            'status' => 'sent',
            'provider_message_id' => 'mock_msg_' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    public function sendNotification(string $phoneNumber, string $message): bool
    {
        Log::info("[SMS Mock Adapter] Message sent to {$phoneNumber}: {$message}");

        DB::table('notifications_log')->insert([
            'user_id' => null,
            'channel' => 'sms',
            'recipient' => $phoneNumber,
            'template_key' => 'general_notification',
            'body' => $message,
            'provider' => 'mock',
            'status' => 'sent',
            'provider_message_id' => 'mock_msg_' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }
}
