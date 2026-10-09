<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Settlement\Service\Money;

use App\Settlement\Service\Money\QuantumAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantumAmountTest extends TestCase
{
    public function testOfNormalizesLeadingZeros(): void
    {
        $q = QuantumAmount::of('007', 'cny', 18);
        self::assertSame('7', $q->quantum);
        self::assertSame('CNY', $q->currency);
    }

    public function testZeroIsPreserved(): void
    {
        $q = QuantumAmount::of('000', 'CNY', 18);
        self::assertSame('0', $q->quantum);
        self::assertTrue($q->isZero());
    }

    public function testRejectsNegativeQuantum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QuantumAmount::of('-5', 'CNY', 18);
    }

    public function testRejectsNegativeDecimal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QuantumAmount::fromDecimal('-5.00', 'CNY', 18);
    }

    public function testRejectsNonNumericQuantum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QuantumAmount::of('12abc', 'CNY', 18);
    }

    public function testRejectsOutOfRangeScale(): void
    {
        $this->expectException(\DomainException::class);
        QuantumAmount::of('1', 'CNY', 19);
    }

    #[DataProvider('decimalProvider')]
    public function testFromDecimal(string $decimal, string $expectedQuantum): void
    {
        $q = QuantumAmount::fromDecimal($decimal, 'CNY', 18);
        self::assertSame($expectedQuantum, $q->quantum);
    }

    public static function decimalProvider(): array
    {
        return [
            ['0', '0'],
            ['12.34', '12340000000000000000'],
            ['12.00', '12000000000000000000'],
            ['12800.5', '12800500000000000000000'],
            ['0.000000000000000001', '1'],
        ];
    }

    public function testToDecimal(): void
    {
        self::assertSame('12.34', QuantumAmount::of('12340000000000000000', 'CNY', 18)->toDecimal());
        self::assertSame('0', QuantumAmount::of('0', 'CNY', 18)->toDecimal());
    }

    public function testToPostingMinorAtScale2(): void
    {
        $q = QuantumAmount::fromDecimal('128.336', 'CNY', 18);
        self::assertSame('12833', $q->toPostingMinor(2));
    }

    public function testPostingRemainder(): void
    {
        // 128.336 at scale 2 => floor 128.33, remainder 0.006
        $q = QuantumAmount::fromDecimal('128.336', 'CNY', 18);
        $remainder = $q->postingRemainder(2);
        self::assertSame('6000000000000000', $remainder->quantum);
        self::assertSame('0.006', $remainder->toDecimal());
    }

    public function testPlusAndMinus(): void
    {
        $a = QuantumAmount::fromDecimal('10.00', 'CNY', 18);
        $b = QuantumAmount::fromDecimal('2.50', 'CNY', 18);
        self::assertSame(QuantumAmount::fromDecimal('12.50', 'CNY', 18)->quantum, $a->plus($b)->quantum);
        self::assertSame(QuantumAmount::fromDecimal('7.50', 'CNY', 18)->quantum, $a->minus($b)->quantum);
    }

    public function testMinusRejectsNegative(): void
    {
        $this->expectException(\DomainException::class);
        QuantumAmount::fromDecimal('2.00', 'CNY', 18)->minus(QuantumAmount::fromDecimal('5.00', 'CNY', 18));
    }

    public function testUnitMismatchRejected(): void
    {
        $this->expectException(\DomainException::class);
        QuantumAmount::of('1', 'CNY', 18)->plus(QuantumAmount::of('1', 'CNY', 2));
    }

    public function testHugeValuesDoNotOverflow(): void
    {
        $a = QuantumAmount::of(str_repeat('9', 100), 'CNY', 18);
        $b = QuantumAmount::of('1', 'CNY', 18);
        $sum = $a->plus($b);
        self::assertSame('1' . str_repeat('0', 100), $sum->quantum);
    }
}
