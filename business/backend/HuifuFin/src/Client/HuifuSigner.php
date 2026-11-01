<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Client;

final class HuifuSigner
{
    /** @param array<string, mixed> $data */
    public function sign(array $data, string $privateKey): string
    {
        ksort($data);
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $key = $this->loadPrivateKey($privateKey);
        $signature = '';
        if (!openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the Huifu request.');
        }

        return base64_encode($signature);
    }

    public function verify(string $data, string $signature, string $publicKey): bool
    {
        $decoded = base64_decode($signature, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }
        $key = $this->loadPublicKey($publicKey);

        return openssl_verify($data, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @param array<string, mixed> $data */
    public function verifyData(array $data, string $signature, string $publicKey): bool
    {
        ksort($data);

        return $this->verify(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $signature,
            $publicKey,
        );
    }

    private function loadPrivateKey(string $key): \OpenSSLAsymmetricKey
    {
        foreach ($this->privateKeyCandidates($key) as $candidate) {
            $loaded = openssl_pkey_get_private($candidate);
            if ($loaded !== false) {
                return $loaded;
            }
        }

        throw new \RuntimeException('The configured Huifu merchant private key is invalid.');
    }

    private function loadPublicKey(string $key): \OpenSSLAsymmetricKey
    {
        foreach ($this->publicKeyCandidates($key) as $candidate) {
            $loaded = openssl_pkey_get_public($candidate);
            if ($loaded !== false) {
                return $loaded;
            }
        }

        throw new \RuntimeException('The configured Huifu public key is invalid.');
    }

    /** @return list<string> */
    private function privateKeyCandidates(string $key): array
    {
        if (str_contains($key, 'BEGIN ')) {
            return [$key];
        }
        $body = preg_replace('/\s+/', '', $key) ?? '';

        return [
            $this->pem($body, 'PRIVATE KEY'),
            $this->pem($body, 'RSA PRIVATE KEY'),
        ];
    }

    /** @return list<string> */
    private function publicKeyCandidates(string $key): array
    {
        if (str_contains($key, 'BEGIN ')) {
            return [$key];
        }
        $body = preg_replace('/\s+/', '', $key) ?? '';

        return [
            $this->pem($body, 'PUBLIC KEY'),
            $this->pem($body, 'RSA PUBLIC KEY'),
        ];
    }

    private function pem(string $body, string $label): string
    {
        return sprintf(
            "-----BEGIN %s-----\n%s\n-----END %s-----",
            $label,
            wordwrap($body, 64, "\n", true),
            $label,
        );
    }
}
