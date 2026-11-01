<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Configuration;

/**
 * Proves that a Core payment or refund call originated from the HuifuFin workflow.
 * The generic Core routes pass request content as gateway options, so they must
 * not be able to consume a Huifu attempt or confirmed refund record directly.
 */
final class HuifuOperationProof
{
    public function __construct(private readonly string $key)
    {
    }

    public function payment(string $attemptUuid): string
    {
        return $this->proof('payment', $attemptUuid);
    }

    public function refund(string $refundUuid): string
    {
        return $this->proof('refund', $refundUuid);
    }

    public function verifyPayment(string $attemptUuid, mixed $proof): bool
    {
        return $this->verify('payment', $attemptUuid, $proof);
    }

    public function verifyRefund(string $refundUuid, mixed $proof): bool
    {
        return $this->verify('refund', $refundUuid, $proof);
    }

    private function proof(string $purpose, string $identifier): string
    {
        $key = trim($this->key);
        if ($key === '') {
            throw new \RuntimeException('HUIFU_CONFIG_KEY must be configured before Huifu payments or refunds can be submitted.');
        }

        return hash_hmac('sha256', $purpose."\0".$identifier, $key);
    }

    private function verify(string $purpose, string $identifier, mixed $proof): bool
    {
        if (!is_string($proof) || $proof === '') {
            return false;
        }
        try {
            return hash_equals($this->proof($purpose, $identifier), $proof);
        } catch (\RuntimeException) {
            return false;
        }
    }
}
