<?php

namespace App\Services\Contracts;

interface SmsClient
{
    /**
     * Send a single SMS message via the configured provider.
     *
     * @param string $to Destination phone in international format (e.g. 2499xxxxxxx)
     * @param string $message Message text
     * @param bool $isOtp Whether this message is an OTP
     * @param string|null $sender Optional sender override
     * @return array{success: bool, provider_id?: string|null, error?: string|null, raw?: mixed}
     */
    public function send(string $to, string $message, bool $isOtp = false, ?string $sender = null): array;

    /**
     * Send multiple SMS messages in one request if supported.
     *
     * @param array<int, array{to: string, message: string, is_otp?: bool}> $messages
     * @param string|null $sender Optional sender override
     * @return array{success: bool, results: array<int, array{to: string, success: bool, provider_id?: string|null, error?: string|null}>, raw?: mixed}
     */
    public function sendBulk(array $messages, ?string $sender = null): array;
}


