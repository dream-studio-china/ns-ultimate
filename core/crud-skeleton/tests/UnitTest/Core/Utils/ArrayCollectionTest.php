<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\ArrayCollection;
use Doctrine\Common\Collections\ArrayCollection as DoctrineArrayCollection;
use PHPUnit\Framework\TestCase;

final class ArrayCollectionTest extends TestCase
{
    public function testInitWithArray(): void
    {
        $result = ArrayCollection::init([1, 2, 3]);

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertCount(3, $result);
        self::assertSame([1, 2, 3], $result->toArray());
    }

    public function testInitWithEmptyArray(): void
    {
        $result = ArrayCollection::init([]);

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testInitWithNonArray(): void
    {
        $result = ArrayCollection::init('not-an-array');

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testInitWithNull(): void
    {
        $result = ArrayCollection::init(null);

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testFromJsonString(): void
    {
        $result = ArrayCollection::fromJsonString('[{"id":1},{"id":2}]');

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertCount(2, $result);
    }

    public function testFromJsonStringWithEmptyArray(): void
    {
        $result = ArrayCollection::fromJsonString('[]');

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testFromJsonStringWithObject(): void
    {
        $result = ArrayCollection::fromJsonString('{"key":"value"}');

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
    }

    public function testMapExtractsPropertyValues(): void
    {
        $obj1 = new class {
            public function getName(): string { return 'Alice'; }
        };
        $obj2 = new class {
            public function getName(): string { return 'Bob'; }
        };

        $result = ArrayCollection::map([$obj1, $obj2], 'name');

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertSame(['Alice', 'Bob'], $result->toArray());
    }

    public function testMapWithArrowFunctions(): void
    {
        $obj = new class {
            public int $val = 0;
            public function getVal(): int { return 42; }
        };

        $result = ArrayCollection::map([$obj], 'val');

        self::assertSame([42], $result->toArray());
    }

    public function testMapWithEmptyInput(): void
    {
        $result = ArrayCollection::map([], 'anything');

        self::assertInstanceOf(DoctrineArrayCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testMapWithDoctrineCollection(): void
    {
        $obj = new class {
            public function getX(): string { return 'y'; }
        };
        $collection = new DoctrineArrayCollection([$obj]);

        $result = ArrayCollection::map($collection, 'x');

        self::assertSame(['y'], $result->toArray());
    }
}
