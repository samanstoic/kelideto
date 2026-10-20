<?php

namespace App\Modules\Payment\Adapters;

use App\Modules\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZarinpalPaymentGatewayAdapter implements PaymentGatewayInterface
{
    private const API_BASE = 'https://payment.zarinpal.com/pg/v4';

    public function name(): string
    {
        return 'zarinpal';
    }

    public function readiness(): array
    {
        if (! $this->merchantId()) {
            return ['status' => 'disconnected', 'reason' => 'GATEWAY_CREDENTIALS_MISSING'];
        }

        $passed = DB::table('gateway_health_checks')
            ->where('gateway_name', $this->name())
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->value('passed');

        return (bool) $passed
            ? ['status' => 'connected', 'reason' => null]
            : ['status' => 'configured_unverified', 'reason' => 'GATEWAY_TEST_REQUIRED'];
    }

    public function request(int $amountMinor, string $callbackUrl, string $description, string $paymentUuid): array
    {
        if ($this->readiness()['status'] !== 'connected') {
            return ['accepted' => false, 'error' => 'GATEWAY_NOT_VERIFIED'];
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::API_BASE.'/payment/request.json', [
                'merchant_id' => $this->merchantId(),
                'amount' => $amountMinor,
                'currency' => 'IRR',
                'callback_url' => $callbackUrl,
                'description' => mb_substr($description, 0, 255),
                'metadata' => ['order_id' => $paymentUuid],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Zarinpal payment request failed with HTTP '.$response->status());
        }

        $body = $response->json();
        $data = is_array($body) ? ($body['data'] ?? []) : [];
        if (($data['code'] ?? null) !== 100 || empty($data['authority'])) {
            return [
                'accepted' => false,
                'error' => 'GATEWAY_REQUEST_REJECTED',
                'code' => (string) ($data['code'] ?? 'UNKNOWN'),
            ];
        }

        return [
            'accepted' => true,
            'authority' => (string) $data['authority'],
            'redirect_url' => 'https://payment.zarinpal.com/pg/StartPay/'.rawurlencode((string) $data['authority']),
        ];
    }

    public function verify(string $authority, int $amountMinor): array
    {
        if (! $this->merchantId()) {
            return ['verified' => false, 'error' => 'GATEWAY_CREDENTIALS_MISSING'];
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::API_BASE.'/payment/verify.json', [
                'merchant_id' => $this->merchantId(),
                'amount' => $amountMinor,
                'authority' => $authority,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Zarinpal payment verification failed with HTTP '.$response->status());
        }

        $body = $response->json();
        $data = is_array($body) ? ($body['data'] ?? []) : [];
        $code = (int) ($data['code'] ?? 0);
        if (! in_array($code, [100, 101], true) || empty($data['ref_id'])) {
            return [
                'verified' => false,
                'error' => 'GATEWAY_VERIFICATION_REJECTED',
                'code' => (string) $code,
            ];
        }

        return [
            'verified' => true,
            'reference' => (string) $data['ref_id'],
            'code' => (string) $code,
        ];
    }

    private function merchantId(): ?string
    {
        $merchantId = trim((string) config('services.payment_gateway.zarinpal.merchant_id'));

        return $merchantId !== '' ? $merchantId : null;
    }
}
