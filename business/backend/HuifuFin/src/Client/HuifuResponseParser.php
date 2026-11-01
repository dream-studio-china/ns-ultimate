<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Client;

final class HuifuResponseParser
{
    /** @param array<string, mixed> $response
     *  @return array<string, mixed> */
    public function data(array $response): array
    {
        $data = $response['data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $response */
    public function code(array $response): ?string
    {
        $data = $this->data($response);
        $code = $data['resp_code'] ?? $response['resp_code'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    /** @param array<string, mixed> $response */
    public function message(array $response): ?string
    {
        $data = $this->data($response);
        $message = $data['resp_desc'] ?? $response['resp_desc'] ?? null;

        return is_scalar($message) ? (string) $message : null;
    }

    /** @param array<string, mixed> $response */
    public function successful(array $response): bool
    {
        return in_array($this->code($response), ['00000100', '00000000'], true);
    }

    /** @param array<string, mixed> $response */
    public function verify(array $response, HuifuSigner $signer, string $publicKey): void
    {
        $signature = $response['sign'] ?? null;
        if (!is_string($signature) || $signature === '') {
            return;
        }
        $data = $this->data($response);
        if ($data === [] || !$signer->verifyData($data, $signature, $publicKey)) {
            throw new \RuntimeException('Huifu response signature verification failed.');
        }
    }

    /** @param array<string, mixed> $data */
    public function transStatus(array $data): ?string
    {
        $status = $data['trans_stat'] ?? $data['trans_status'] ?? null;

        return is_scalar($status) ? (string) $status : null;
    }
}
