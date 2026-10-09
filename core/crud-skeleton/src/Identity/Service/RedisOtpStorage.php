<?php

declare(strict_types=1);

namespace App\Identity\Service;

use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisOtpStorage implements OtpStorageInterface
{
    /**
     * @var object Redis-like client returned by RedisAdapter::createConnection
     */
    private readonly object $redis;

    public function __construct(string $redisDsn)
    {
        $this->redis = RedisAdapter::createConnection($redisDsn);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function get(string $key): string|false
    {
        $result = $this->redis->get($key);

        return \is_string($result) ? $result : false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return (bool) $this->redis->setex($key, $ttl, $value);
    }

    public function del(string ...$keys): int
    {
        $result = $this->redis->del(...$keys);

        return is_int($result) ? $result : 0;
    }

    public function ttl(string $key): int
    {
        $result = $this->redis->ttl($key);

        return is_int($result) ? $result : 0;
    }
}
