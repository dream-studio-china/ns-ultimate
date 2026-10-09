<?php

declare(strict_types=1);

namespace App\Identity\Service;

interface OtpStorageInterface
{
    public function exists(string $key): bool;
    public function get(string $key): string|false;
    public function setex(string $key, int $ttl, string $value): bool;
    public function del(string ...$keys): int;
    public function ttl(string $key): int;
}
