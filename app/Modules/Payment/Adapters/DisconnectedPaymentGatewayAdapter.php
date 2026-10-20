<?php

namespace App\Modules\Payment\Adapters;

use App\Modules\Payment\Contracts\PaymentGatewayInterface;

class DisconnectedPaymentGatewayAdapter implements PaymentGatewayInterface
{
    public function name(): string
    {
        return (string) config('services.payment_gateway.driver', 'disconnected');
    }

    public function readiness(): array
    {
        return ['status' => 'disconnected', 'reason' => 'GATEWAY_CREDENTIALS_MISSING'];
    }

    public function request(int $amountMinor, string $callbackUrl, string $description, string $paymentUuid): array
    {
        return ['accepted' => false, 'error' => 'GATEWAY_DISCONNECTED'];
    }

    public function verify(string $authority, int $amountMinor): array
    {
        return ['verified' => false, 'error' => 'GATEWAY_DISCONNECTED'];
    }
}
