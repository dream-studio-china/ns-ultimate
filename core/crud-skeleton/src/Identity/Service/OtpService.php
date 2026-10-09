<?php

declare(strict_types=1);

namespace App\Identity\Service;

use App\Identity\Sms\SmsProviderInterface;
use Psr\Log\LoggerInterface;

final class OtpService implements OtpServiceInterface
{
    public function __construct(
        private readonly OtpStorageInterface $storage,
        private readonly SmsProviderInterface $sms,
        private readonly int $ttl = 300,
        private readonly int $resendInterval = 60,
        private readonly int $maxAttempts = 5,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Generate an OTP and send it via SMS.
     *
     * @throws \RuntimeException When rate-limited or send fails
     */
    public function generateAndSend(string $phone, string $purpose, string $templateCode): void
    {
        $key = $this->buildKey($purpose, $phone);
        $cooldownKey = $key . ':cooldown';

        if ($this->storage->exists($cooldownKey)) {
            throw new \RuntimeException('OTP sent too recently, please wait.');
        }

        $otp = (string) random_int(100000, 999999);
        $hash = $this->hashOtp($otp);

        $payload = json_encode([
            'hash' => $hash,
            'tries' => 0,
        ], JSON_THROW_ON_ERROR);

        $this->storage->setex($key, $this->ttl, $payload);
        $this->storage->setex($cooldownKey, $this->resendInterval, '1');

        $sent = $this->sms->sendSms($phone, $templateCode, ['code' => $otp]);

        if (!$sent) {
            $this->storage->del($key, $cooldownKey);
            throw new \RuntimeException('Failed to send SMS.');
        }

        $this->logger?->info('[OTP] Sent', [
            'purpose' => $purpose,
            'phone' => $this->maskPhone($phone),
        ]);
    }

    /**
     * Verify an OTP for a given phone and purpose.
     */
    public function verify(string $phone, string $purpose, string $submittedOtp): bool
    {
        $key = $this->buildKey($purpose, $phone);
        $raw = $this->storage->get($key);

        if ($raw === false) {
            return false;
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $storedHash = $data['hash'] ?? '';
        $tries = (int) ($data['tries'] ?? 0);

        if (!hash_equals($storedHash, $this->hashOtp($submittedOtp))) {
            $tries++;
            if ($tries >= $this->maxAttempts) {
                $this->storage->del($key);
                $this->logger?->info('[OTP] Max attempts exceeded', [
                    'purpose' => $purpose,
                    'phone' => $this->maskPhone($phone),
                ]);

                return false;
            }
            $data['tries'] = $tries;
            $ttl = $this->storage->ttl($key);
            $this->storage->setex($key, $ttl > 0 ? $ttl : $this->ttl, json_encode($data, JSON_THROW_ON_ERROR));

            return false;
        }

        $this->storage->del($key);

        return true;
    }

    private function buildKey(string $purpose, string $phone): string
    {
        return "otp:{$purpose}:{$phone}";
    }

    public function hashOtp(string $otp): string
    {
        return hash_hmac('sha256', $otp, 'otp_secret_salt');
    }

    private function maskPhone(string $phone): string
    {
        if (mb_strlen($phone) <= 4) {
            return '***';
        }

        return mb_substr($phone, 0, 3) . '****' . mb_substr($phone, -4);
    }
}
