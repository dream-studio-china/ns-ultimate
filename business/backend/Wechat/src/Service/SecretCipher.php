<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

/**
 * Authenticated encryption for WeChat credential material.
 *
 * The master key is supplied out of band as a base64-encoded 32-byte value
 * (WECHAT_CREDENTIAL_KEY) and is never persisted with the ciphertext. Each
 * payload records the encryption key id so that keys can be rotated.
 */
final class SecretCipher
{
    private const PREFIX = 'v1';
    private const ALGO = 'aes-256-gcm';
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private ?string $key = null;

    public function __construct(private readonly string $encodedKey)
    {
    }

    public function keyId(): string
    {
        return substr(hash('sha256', $this->key()), 0, 16);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ALGO,
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH,
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('WeChat credential encryption failed.');
        }

        return implode(':', [
            self::PREFIX,
            $this->keyId(),
            base64_encode($nonce),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    public function decrypt(string $payload): string
    {
        $parts = explode(':', $payload);
        if (count($parts) !== 5 || $parts[0] !== self::PREFIX) {
            throw new \RuntimeException('Unsupported WeChat credential payload format.');
        }

        [, $nonce, $tag, $ciphertext] = $parts;
        $nonceBytes = base64_decode($parts[2], true);
        $tagBytes = base64_decode($parts[3], true);
        $ciphertextBytes = base64_decode($parts[4], true);

        if ($nonceBytes === false || $tagBytes === false || $ciphertextBytes === false) {
            throw new \RuntimeException('WeChat credential payload is not valid base64.');
        }

        $plaintext = openssl_decrypt(
            $ciphertextBytes,
            self::ALGO,
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonceBytes,
            $tagBytes,
        );
        if ($plaintext === false) {
            throw new \RuntimeException('WeChat credential decryption failed; the payload or key is invalid.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $decoded = base64_decode(trim($this->encodedKey), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('WECHAT_CREDENTIAL_KEY must be a base64-encoded 32-byte key.');
        }

        return $this->key = $decoded;
    }
}
