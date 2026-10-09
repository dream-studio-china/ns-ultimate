<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\ExpressionService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * Covers the cache read / cache write paths of ExpressionService::buildFilter()
 * plus the ArrayCollection parameter conversion branch.
 */
final class ExpressionServiceCoverageTest extends TestCase
{
    public function testBuildFilterReadsFromCacheAndRebuildsQuery(): void
    {
        $cacheKey = 'expr_' . sha1('SomeEntity|a==b');

        $cache = new ExprCovCache([
            $cacheKey => [
                'dql' => 'SELECT e FROM Entity e WHERE e.id = :p1',
                'parameters' => [
                    ['n' => 'p1', 'v' => 42],
                ],
            ],
        ]);

        $em = new ExprCovEntityManager();
        $service = new ExpressionService($cache);

        $result = $service->buildFilter('a==b', 'SomeEntity', [], $em);

        self::assertSame($em->lastQuery, $result['qb']);
        self::assertCount(1, $result['parameters']);
        $parameter = $result['parameters'][0];
        self::assertInstanceOf(\Doctrine\ORM\Query\Parameter::class, $parameter);
        self::assertSame('p1', $parameter->getName());
        self::assertSame(42, $parameter->getValue());
    }

    public function testBuildFilterIgnoresGarbageCacheEntry(): void
    {
        $cacheKey = 'expr_' . sha1('SomeEntity|a==b');
        $cache = new ExprCovCache([$cacheKey => 'not-an-array']);

        $service = new class($cache) extends ExpressionService {
            protected function parseAndAssemble(string $filter, string $dataClass, array $values, $em): array
            {
                $qb = new class {
                    public function getDQL(): string
                    {
                        return 'SELECT x FROM X x';
                    }
                };

                return ['qb' => $qb, 'parameters' => []];
            }
        };
        $result = $service->buildFilter('a==b', 'SomeEntity', [], new ExprCovEntityManager());

        // Cache entry is invalid, so it falls through to parseAndAssemble (empty params).
        self::assertArrayHasKey('qb', $result);
        self::assertIsArray($result['parameters']);
        self::assertCount(0, $result['parameters']);
    }

    public function testBuildFilterStoresCacheRepresentation(): void
    {
        $cacheKey = 'expr_' . sha1('SomeEntity|a==b');
        $cache = new ExprCovCache([]);

        $service = new class($cache) extends ExpressionService {
            protected function parseAndAssemble(string $filter, string $dataClass, array $values, $em): array
            {
                $qb = new class {
                    public function getDQL(): string
                    {
                        return 'SELECT x FROM X x';
                    }
                };

                return ['qb' => $qb, 'parameters' => [
                    new \Doctrine\ORM\Query\Parameter('p1', 7),
                    new \Doctrine\ORM\Query\Parameter('p2', 'str'),
                ]];
            }
        };

        $result = $service->buildFilter('a==b', 'SomeEntity', [], new ExprCovEntityManager());

        self::assertArrayHasKey('qb', $result);
        $stored = $cache->get($cacheKey);
        self::assertIsArray($stored);
        self::assertSame('SELECT x FROM X x', $stored['dql']);
        self::assertSame(
            [['n' => 'p1', 'v' => 7], ['n' => 'p2', 'v' => 'str']],
            $stored['parameters']
        );
    }

    public function testBuildFilterConvertsArrayCollectionParametersToArray(): void
    {
        $service = new class extends ExpressionService {
            protected function parseAndAssemble(string $filter, string $dataClass, array $values, $em): array
            {
                $qb = new class {
                    public function getDQL(): string
                    {
                        return 'SELECT x FROM X x';
                    }
                };

                return ['qb' => $qb, 'parameters' => new ArrayCollection([
                    new \Doctrine\ORM\Query\Parameter('col1', 'val'),
                ])];
            }
        };

        $result = $service->buildFilter('a==b', 'SomeEntity', [], new ExprCovEntityManager());

        self::assertIsArray($result['parameters']);
        self::assertCount(1, $result['parameters']);
        self::assertSame('col1', $result['parameters'][0]->getName());
    }
}

final class ExprCovEntityManager
{
    public ?object $lastQuery = null;

    public function createQuery(string $dql): object
    {
        $this->lastQuery = new class ($dql) {
            public function __construct(private readonly string $dql)
            {
            }

            public function getDQL(): string
            {
                return $this->dql;
            }
        };

        return $this->lastQuery;
    }
}

final class ExprCovCache implements CacheInterface
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
}
