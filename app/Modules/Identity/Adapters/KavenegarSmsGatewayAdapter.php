<?php

namespace App\Modules\Identity\Adapters;

use App\Modules\Identity\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KavenegarSmsGatewayAdapter implements SmsGatewayInterface
{
    protected ?string $apiKey;
    protected string $sender;

    public function __construct()
    {
        $this->apiKey = config('services.kavenegar.api_key');
        $this->sender = config('services.kavenegar.sender', '10008663');
    }

    public function sendOtp(string $phoneNumber, string $code): bool
    {
        if (empty($this->apiKey)) {
            Log::warning("[Kavenegar Adapter] API Key not set, falling back to mock logging.");
            return true;
        }

        try {
            $response = Http::get("https://api.kavenegar.com/v1/{$this->apiKey}/verify/lookup.json", [
                'receptor' => $phoneNumber,
                'token' => $code,
                'template' => 'keypro-verify',
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("[Kavenegar Adapter] Send failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendNotification(string $phoneNumber, string $message): bool
    {
        if (empty($this->apiKey)) {
            return true;
        }

        try {
            $response = Http::post("https://api.kavenegar.com/v1/{$this->apiKey}/sms/send.json", [
                'receptor' => $phoneNumber,
                'sender' => $this->sender,
                'message' => $message,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("[Kavenegar Adapter] Send notification failed: " . $e->getMessage());
            return false;
        }
    }
}
