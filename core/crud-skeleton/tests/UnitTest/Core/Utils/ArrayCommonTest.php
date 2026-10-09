<?php

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\ArrayCommon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ArrayCommonTest extends TestCase
{
    #[Group('low-value')]
    public function testInArrayAndCount(): void
    {
        self::assertTrue(ArrayCommon::in_array('x', ['x', 'y']));
        self::assertSame(2, ArrayCommon::count(['x', 'y']));
    }

    #[Group('low-value')]
    public function testPushAndKeyExist(): void
    {
        $out = ArrayCommon::push([1], 2);

        self::assertSame([1, 2], $out);
        self::assertTrue(ArrayCommon::key_exist('a', ['a' => 1]));
    }

    public function testMergeMatchesCurrentLegacyBehavior(): void
    {
        self::assertSame([[1], [2]], ArrayCommon::merge([1], [2]));
    }
}
