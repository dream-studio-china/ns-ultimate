<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\Math;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class MathExtendedTest extends TestCase
{
    #[Group('low-value')]
    public function testRandomInRange(): void
    {
        $val = Math::random(5, 10);
        self::assertGreaterThanOrEqual(5, $val);
        self::assertLessThanOrEqual(10, $val);
    }

    public function testAcos(): void
    {
        self::assertSame(acos(0.5), Math::acos(0.5));
    }

    public function testAsin(): void
    {
        self::assertSame(asin(0.5), Math::asin(0.5));
    }

    public function testAtan(): void
    {
        self::assertSame(atan(1), Math::atan(1));
    }

    public function testAtan2(): void
    {
        self::assertSame(atan2(1, 1), Math::atan2(1, 1));
    }

    public function testCosh(): void
    {
        self::assertSame(cosh(0), Math::cosh(0));
    }

    public function testSinh(): void
    {
        self::assertSame(sinh(0), Math::sinh(0));
    }

    public function testTanh(): void
    {
        self::assertSame(tanh(0), Math::tanh(0));
    }

    public function testFmod(): void
    {
        self::assertSame(fmod(5.7, 1.3), Math::fmod(5.7, 1.3));
    }

    public function testHypot(): void
    {
        self::assertSame(hypot(3, 4), Math::hypot(3, 4));
    }

    public function testLog10(): void
    {
        self::assertSame(log10(100), Math::log10(100));
    }

    public function testPi(): void
    {
        self::assertSame(pi(), Math::pi());
    }

    public function testIsFinite(): void
    {
        self::assertTrue(Math::is_finite(42.0));
        self::assertFalse(Math::is_finite(log(0)));
    }

    public function testIsInfinite(): void
    {
        self::assertFalse(Math::is_infinite(42.0));
    }

    public function testIsNan(): void
    {
        self::assertFalse(Math::is_nan(42.0));
        self::assertTrue(Math::is_nan(NAN));
    }

    public function testExpM1(): void
    {
        self::assertSame(expm1(0), Math::expm1(0));
    }

    public function testLog1P(): void
    {
        self::assertSame(log1p(0), Math::log1p(0));
    }

    public function testBaseConvert(): void
    {
        self::assertSame('ff', Math::base_convert('255', 10, 16));
    }

    public function testBindec(): void
    {
        self::assertSame(10, Math::bindec('1010'));
    }

    public function testDecbin(): void
    {
        self::assertSame('1010', Math::decbin(10));
    }

    public function testDechex(): void
    {
        self::assertSame('a', Math::dechex(10));
    }

    public function testDecoct(): void
    {
        self::assertSame('12', Math::decoct(10));
    }

    public function testHexdec(): void
    {
        self::assertSame(26, Math::hexdec('1a'));
    }

    public function testOctdec(): void
    {
        self::assertSame(8, Math::octdec('10'));
    }




    #[Group('low-value')]
    public function testAllConstants(): void
    {
        self::assertGreaterThan(2.0, Math::M_PI);
        self::assertGreaterThan(2.0, Math::M_E);
        self::assertGreaterThan(1.0, Math::M_SQRT2);
        self::assertGreaterThan(1.0, Math::M_SQRT3);
        self::assertGreaterThan(1.0, Math::M_PI_2);
        self::assertGreaterThan(0.0, Math::M_PI_4);
        self::assertGreaterThan(0.0, Math::M_1_PI);
        self::assertGreaterThan(0.0, Math::M_2_PI);
        self::assertGreaterThan(1.0, Math::M_SQRTPI);
        self::assertGreaterThan(1.0, Math::M_2_SQRTPI);
        self::assertGreaterThan(0.0, Math::M_SQRT1_2);
        self::assertGreaterThan(0.0, Math::M_LN2);
        self::assertGreaterThan(0.0, Math::M_LN10);
        self::assertGreaterThan(0.0, Math::M_LOG2E);
        self::assertGreaterThan(0.0, Math::M_LOG10E);
        self::assertGreaterThan(0.0, Math::M_EULER);
        self::assertGreaterThan(0.0, Math::M_LNPI);
    }
}
