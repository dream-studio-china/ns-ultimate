<?php

declare(strict_types=1);

namespace App\Identity\Service;

interface OtpServiceInterface
{
    /**
     * Generate an OTP and send it via SMS.
     *
     * @throws \RuntimeException When rate-limited or send fails
     */
    public function generateAndSend(string $phone, string $purpose, string $templateCode): void;

    /**
     * Verify an OTP for a given phone and purpose.
     */
    public function verify(string $phone, string $purpose, string $submittedOtp): bool;

    public function hashOtp(string $otp): string;
}
