<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Support;

use NsUltimate\Business\HuifuFin\Client\HuifuClientInterface;

final class FakeHuifuClient implements HuifuClientInterface
{
    /** @var list<array{path: string, payload: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $responses = [];

    public function post(string $path, array $payload, string $environment = 'production'): array
    {
        $this->requests[] = ['path' => $path, 'payload' => $payload, 'environment' => $environment];

        return array_shift($this->responses) ?? [];
    }
}
