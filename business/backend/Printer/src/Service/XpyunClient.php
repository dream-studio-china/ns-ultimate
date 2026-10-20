<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class XpyunClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $user,
        private readonly string $userKey,
    ) {
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function request(string $endpoint, array $payload): array
    {
        if ($this->user === '' || $this->userKey === '') {
            throw new \LogicException('芯烨云账号配置不完整，请设置 XPYUN_USER 和 XPYUN_USER_KEY。');
        }
        $timestamp = (string) time();
        $payload += [
            'user' => $this->user,
            'timestamp' => $timestamp,
            'sign' => sha1($this->user.$this->userKey.$timestamp),
        ];

        $response = $this->httpClient->request('POST', 'https://open.xpyun.net/api/openapi/xprinter/'.$endpoint, [
            'json' => $payload,
            'timeout' => 10,
        ]);
        $data = $response->toArray(false);
        if (!is_array($data) || !isset($data['code']) || (int) $data['code'] !== 0) {
            $code = is_array($data) ? ($data['code'] ?? 'invalid_response') : 'invalid_response';
            throw new \RuntimeException(sprintf('芯烨云请求失败（%s）。', (string) $code));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function print(string $kind, string $serialNumber, string $content, int $copies = 1, ?string $idempotent = null): array
    {
        if (!in_array($kind, ['receipt', 'label'], true)) {
            throw new \InvalidArgumentException('Unsupported printer kind.');
        }
        if ($copies < 1 || $copies > 100) {
            throw new \InvalidArgumentException('Copies must be between 1 and 100.');
        }
        if (strlen(iconv('UTF-8', 'GBK//IGNORE', $content) ?: '') > 12 * 1024) {
            throw new \InvalidArgumentException('Rendered print content exceeds the 12 KB GBK limit.');
        }
        $payload = ['sn' => $serialNumber, 'content' => $content, 'copies' => $copies, 'mode' => 0];
        if ($idempotent !== null) {
            $payload['idempotent'] = substr($idempotent, 0, 50);
        }
        return $this->request($kind === 'receipt' ? 'print' : 'printLabel', $payload);
    }
}
