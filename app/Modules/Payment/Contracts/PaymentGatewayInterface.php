<?php

namespace App\Modules\Payment\Contracts;

interface PaymentGatewayInterface
{
    public function name(): string;

    public function readiness(): array;

    public function request(int $amountMinor, string $callbackUrl, string $description, string $paymentUuid): array;

    public function verify(string $authority, int $amountMinor): array;
}
