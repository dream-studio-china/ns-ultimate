<?php

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\ArrayCommon;
use PHPUnit\Framework\TestCase;

final class ArrayCommonExtendedTest extends TestCase
{
    public function testFilter(): void
    {
        $result = ArrayCommon::filter([1, 2, 3, 4, 5], 'value > 3');
        self::assertSame([4, 5], array_values($result));
    }

    public function testFilterWithExternal(): void
    {
        $result = ArrayCommon::filter([1, 2, 3], 'value > min', ['min' => 1]);
        self::assertSame([2, 3], array_values($result));
    }

    public function testFilterEmpty(): void
    {
        $result = ArrayCommon::filter([], 'value > 0');
        self::assertSame([], $result);
    }

    public function testFilterNoMatch(): void
    {
        $result = ArrayCommon::filter([1, 2], 'value > 10');
        self::assertSame([], $result);
    }

    public function testMap(): void
    {
        $result = ArrayCommon::map([1, 2, 3], 'item * 2');
        self::assertSame([2, 4, 6], $result);
    }

    public function testMapWithExternal(): void
    {
        $result = ArrayCommon::map([1, 2], 'item + add', ['add' => 10]);
        self::assertSame([11, 12], $result);
    }

    public function testMapEmpty(): void
    {
        $result = ArrayCommon::map([], 'item * 2');
        self::assertSame([], $result);
    }

    public function testReduce(): void
    {
        $result = ArrayCommon::reduce([1, 2, 3, 4], 'carry + item', 0);
        self::assertSame(10, $result);
    }

    public function testReduceWithExternal(): void
    {
        $result = ArrayCommon::reduce([1, 2, 3], 'carry + item + add', 0, ['add' => 10]);
        self::assertSame(36, $result);
    }

    public function testReduceEmpty(): void
    {
        $result = ArrayCommon::reduce([], 'carry + item', 42);
        self::assertSame(42, $result);
    }

    public function testReduceNoInitial(): void
    {
        $result = ArrayCommon::reduce([10, 20, 30], 'carry + item');
        self::assertSame(60, $result);
    }
}
