<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class AuthorizationCacheInvalidator
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /** @param list<string> $userUuids */
    public function invalidateUsers(array $userUuids): void
    {
        foreach ($userUuids as $uuid) {
            $this->cache->delete('authorization_effective_'.$uuid);
        }
    }

    public function invalidateUser(string $userUuid): void
    {
        $this->cache->delete('authorization_effective_'.$userUuid);
    }
}
