<?php

declare(strict_types=1);

namespace App\Identity\Service;

/**
 * Null implementation of OtpStorageInterface for testing.
 * All operations succeed without real storage.
 */
final class NullOtpStorage implements OtpStorageInterface
{
    /** @var array<string, string> */
    private array $data = [];
    /** @var array<string, int> */
    private array $ttls = [];

    public function exists(string $key): bool
    {
        return isset($this->data[$key]) && ($this->ttls[$key] ?? 0) > time();
    }

    public function get(string $key): string|false
    {
        if (isset($this->data[$key]) && ($this->ttls[$key] ?? 0) > time()) {
            return $this->data[$key];
        }
        return false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->data[$key] = $value;
        $this->ttls[$key] = time() + $ttl;
        return true;
    }

    public function del(string ...$keys): int
    {
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->data[$key])) {
                unset($this->data[$key], $this->ttls[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function ttl(string $key): int
    {
        if (!isset($this->ttls[$key])) {
            return -2;
        }
        $remaining = $this->ttls[$key] - time();
        return $remaining > 0 ? (int) $remaining : -2;
    }
}
