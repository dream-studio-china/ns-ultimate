<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Support;

use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;

final class FakeHuifuClient implements HuifuClientInterface
{
    /** @var list<array{path: string, payload: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $responses = [];

    public function __construct(
        private readonly ?string $privateKey = null,
        private readonly HuifuSigner $signer = new HuifuSigner(),
    ) {
    }

    public function post(string $path, array $payload, string $environment = 'production'): array
    {
        $this->requests[] = ['path' => $path, 'payload' => $payload, 'environment' => $environment];
        $response = array_shift($this->responses) ?? [];
        if ($this->privateKey !== null && isset($response['data']) && is_array($response['data'])) {
            $response['sign'] = $this->signer->sign($response['data'], $this->privateKey);
        }

        return $response;
    }
}
