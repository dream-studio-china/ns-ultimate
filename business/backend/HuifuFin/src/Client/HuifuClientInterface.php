<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Client;

interface HuifuClientInterface
{
    /** @param array<string, mixed> $payload
     *  @return array<string, mixed> */
    public function post(string $path, array $payload, string $environment = 'production'): array;
}
