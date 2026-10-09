<?php

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\Math;
use PHPUnit\Framework\TestCase;

final class MathTest extends TestCase
{
    public function testRandomRange(): void
    {
        $val = Math::random(10, 20);
        self::assertGreaterThanOrEqual(10, $val);
        self::assertLessThan(20, $val);
    }

    public function testRandomDefault(): void
    {
        $val = Math::random();
        self::assertGreaterThanOrEqual(0, $val);
        self::assertLessThan(1, $val);
    }

    public function testAbs(): void
    {
        self::assertSame(5, Math::abs(-5));
        self::assertSame(5, Math::abs(5));
        self::assertSame(0, Math::abs(0));
    }

    public function testCeil(): void
    {
        self::assertSame(4.0, Math::ceil(3.14));
        self::assertSame(4.0, Math::ceil(3.9));
    }

    public function testFloor(): void
    {
        self::assertSame(3.0, Math::floor(3.14));
        self::assertSame(3.0, Math::floor(3.9));
    }

    public function testRound(): void
    {
        self::assertSame(3.0, Math::round(3.14, 0));
        self::assertSame(3.1, Math::round(3.14, 1));
    }

    public function testSqrt(): void
    {
        self::assertSame(3.0, Math::sqrt(9));
        self::assertSame(0.0, Math::sqrt(0));
    }

    public function testPow(): void
    {
        self::assertEquals(8, Math::pow(2, 3));
        self::assertEquals(1, Math::pow(5, 0));
    }

    public function testTrigFunctions(): void
    {
        self::assertSame(0.0, Math::sin(0));
        self::assertSame(1.0, Math::cos(0));
        self::assertSame(0.0, Math::tan(0));
    }

    public function testMaxMin(): void
    {
        self::assertSame(5, Math::max(1, 3, 5, 2));
        self::assertSame(1, Math::min(3, 1, 5, 2));
    }

    public function testDegRad(): void
    {
        self::assertSame(M_PI, Math::deg2rad(180));
        self::assertSame(180.0, Math::rad2deg(M_PI));
    }

    public function testBaseConvert(): void
    {
        $result = Math::base_convert('FF', 16, 10);
        self::assertSame('255', $result);
    }

    public function testExpLog(): void
    {
        self::assertSame(1.0, Math::exp(0));
        self::assertSame(0.0, Math::log(1));
    }

    public function testLocationDistance(): void
    {
        // Same point: distance should be 0
        $d = Math::locationDistance(0, 0, 0, 0);
        self::assertSame(0.0, $d);

        // Different points: should be > 0
        $d = Math::locationDistance(116.407, 39.904, 121.473, 31.230);
        self::assertGreaterThan(0, $d);
    }

    public function testConstants(): void
    {
        self::assertSame(M_PI, Math::M_PI);
        self::assertSame(M_E, Math::M_E);
        self::assertSame(M_SQRT2, Math::M_SQRT2);
    }
}
