<?php

namespace App\Tests\UnitTest\Core\Service;

use App\Core\Service\QueryBuilderFactory;
use PHPUnit\Framework\TestCase;

final class QueryBuilderFactoryTest extends TestCase
{
    public function testCreateRootQueryBuilderCallsSelectAndFrom(): void
    {
        $qb = new class {
            public ?string $selectAlias = null;
            public ?string $class = null;
            public ?string $fromAlias = null;

            public function select(string $alias): self
            {
                $this->selectAlias = $alias;
                return $this;
            }

            public function from(string $class, string $alias): self
            {
                $this->class = $class;
                $this->fromAlias = $alias;
                return $this;
            }
        };

        $em = new class($qb) {
            public function __construct(private readonly object $qb)
            {
            }

            public function createQueryBuilder(): object
            {
                return $this->qb;
            }
        };

        $factory = new QueryBuilderFactory($em);
        $result = $factory->create('App\\Common\\Entity\\Content', 'entity');

        self::assertSame($qb, $result);
        self::assertSame('entity', $qb->selectAlias);
        self::assertSame('App\\Common\\Entity\\Content', $qb->class);
        self::assertSame('entity', $qb->fromAlias);
    }
}
