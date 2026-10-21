<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

final class PrinterCredentialCipher
{
    private ?string $key;

    public function __construct(string $key)
    {
        $this->key = $key === '' ? null : hash('sha256', $key, true);
    }

    /** @return array{userKey: string} */
    public function decrypt(string $cipher): array
    {
        $key = $this->configuredKey();
        $binary = base64_decode($cipher, true);
        if ($binary === false || strlen($binary) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { throw new \RuntimeException('Stored printer credential is invalid.'); }
        $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if ($plain === false) { throw new \RuntimeException('Unable to decrypt stored printer credential.'); }
        $data = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['userKey'] ?? null)) { throw new \RuntimeException('Stored printer credential is invalid.'); }
        return ['userKey' => $data['userKey']];
    }

    public function encrypt(string $userKey): string
    {
        if (trim($userKey) === '') { throw new \InvalidArgumentException('The Xpyun developer key is required.'); }
        $key = $this->configuredKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = json_encode(['userKey' => trim($userKey)], JSON_THROW_ON_ERROR);
        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $key));
    }

    private function configuredKey(): string
    {
        return $this->key ?? throw new \InvalidArgumentException('PRINTER_CONFIG_KEY must be configured before managing printer credentials.');
    }
}
