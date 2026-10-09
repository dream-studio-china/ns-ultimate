<?php

declare(strict_types=1);

namespace App\Identity\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Local OTP storage backed by Symfony cache pool (cache.app).
 * Suitable as a temporary replacement when Redis is unavailable.
 */
final class LocalCacheOtpStorage implements OtpStorageInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function exists(string $key): bool
    {
        return $this->cache->getItem($this->dataKey($key))->isHit();
    }

    public function get(string $key): string|false
    {
        $item = $this->cache->getItem($this->dataKey($key));
        if (!$item->isHit()) {
            return false;
        }

        $value = $item->get();

        return \is_string($value) ? $value : false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $safeTtl = max(1, $ttl);

        $dataItem = $this->cache->getItem($this->dataKey($key));
        $dataItem->set($value);
        $dataItem->expiresAfter($safeTtl);

        $metaItem = $this->cache->getItem($this->metaKey($key));
        $metaItem->set(time() + $safeTtl);
        $metaItem->expiresAfter($safeTtl);

        return $this->cache->save($dataItem) && $this->cache->save($metaItem);
    }

    public function del(string ...$keys): int
    {
        $deleted = 0;

        foreach ($keys as $key) {
            $dataKey = $this->dataKey($key);
            $metaKey = $this->metaKey($key);

            $hit = $this->cache->getItem($dataKey)->isHit();

            $this->cache->deleteItem($dataKey);
            $this->cache->deleteItem($metaKey);

            if ($hit) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function ttl(string $key): int
    {
        $dataItem = $this->cache->getItem($this->dataKey($key));
        if (!$dataItem->isHit()) {
            return -2;
        }

        $metaItem = $this->cache->getItem($this->metaKey($key));
        if (!$metaItem->isHit()) {
            return -1;
        }

        $expiresAt = (int) $metaItem->get();
        $remaining = $expiresAt - time();

        return $remaining > 0 ? $remaining : -2;
    }

    private function dataKey(string $key): string
    {
        return 'otp.data.' . sha1($key);
    }

    private function metaKey(string $key): string
    {
        return 'otp.meta.' . sha1($key);
    }
}
