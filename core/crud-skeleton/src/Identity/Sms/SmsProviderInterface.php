<?php

declare(strict_types=1);

namespace App\Identity\Sms;

interface SmsProviderInterface
{
    /**
     * Send a templated SMS to a phone number.
     *
     * @param string $phone  E.164 formatted phone number
     * @param string $templateCode  Provider-specific template identifier
     * @param array<string, string> $params  Template parameters (e.g. ['code' => '123456'])
     *
     * @return bool Whether the SMS was accepted for delivery
     */
    public function sendSms(string $phone, string $templateCode, array $params): bool;
}
