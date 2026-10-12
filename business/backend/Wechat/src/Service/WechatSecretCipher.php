<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

final class WechatSecretCipher
{
    private ?string $key;

    public function __construct(string $key)
    {
        $this->key = $key === '' ? null : hash('sha256', $key, true);
    }

    /** @param array<string, string> $credentials */
    public function encrypt(array $credentials): string
    {
        $key = $this->configuredKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = json_encode($credentials, JSON_THROW_ON_ERROR);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $key));
    }

    /** @return array<string, string> */
    public function decrypt(string $cipher): array
    {
        $key = $this->configuredKey();
        $encoded = base64_decode($cipher, true);
        if ($encoded === false || strlen($encoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored WeChat credentials are invalid.');
        }
        $nonce = substr($encoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($encoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt WeChat credentials.');
        }
        $data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Stored WeChat credentials are invalid.');
        }

        return $data;
    }

    private function configuredKey(): string
    {
        return $this->key ?? throw new \InvalidArgumentException('WECHAT_CONFIG_KEY must be configured before managing WeChat credentials.');
    }
}
