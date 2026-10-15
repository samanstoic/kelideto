<?php

namespace App\Modules\Identity\Contracts;

interface SmsGatewayInterface
{
    /**
     * Send OTP verification code to Iranian mobile number.
     *
     * @param string $phoneNumber (e.g. 09121234567)
     * @param string $code (6-digit dynamic code)
     * @return bool
     */
    public function sendOtp(string $phoneNumber, string $code): bool;

    /**
     * Send arbitrary notification text message.
     *
     * @param string $phoneNumber
     * @param string $message
     * @return bool
     */
    public function sendNotification(string $phoneNumber, string $message): bool;
}
