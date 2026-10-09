<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Service;

use App\Inventory\Service\Quantity;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function testNormalizesAndAddsSignedQuantities(): void
    {
        self::assertSame('12.340000', Quantity::normalize('00012.34'));
        self::assertSame('-1.250000', Quantity::add('1.250000', '-2.500000'));
        self::assertSame('3.750000', Quantity::subtract('5.000000', '1.250000'));
    }

    public function testMultipliesExactSixScaleQuantities(): void
    {
        self::assertSame('2.500000', Quantity::multiply('2.000000', '1.250000'));
        self::assertSame('0.125000', Quantity::multiply('0.500000', '0.250000'));
        self::assertSame('-3.000000', Quantity::multiply('-1.500000', '2.000000'));
    }

    public function testRejectsPrecisionLossAndInvalidQuantities(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quantity::multiply('0.333333', '0.333333');
    }

    public function testRejectsArithmeticOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quantity::add('99999999999999.999999', '0.000001');
    }
}
