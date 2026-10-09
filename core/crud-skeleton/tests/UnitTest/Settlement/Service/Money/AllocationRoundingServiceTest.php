<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Settlement\Service\Money;

use App\Settlement\Service\Money\AllocationRoundingService;
use App\Settlement\Service\Money\QuantumAmount;
use PHPUnit\Framework\TestCase;

final class AllocationRoundingServiceTest extends TestCase
{
    private AllocationRoundingService $service;

    protected function setUp(): void
    {
        $this->service = new AllocationRoundingService();
    }

    private function q(string $decimal): string
    {
        return QuantumAmount::fromDecimal($decimal, 'CNY', 18)->quantum;
    }

    public function testExactNoResidual(): void
    {
        // 12.50 split into 10.00 + 2.50 at scale 2 => no dust.
        $result = $this->service->distribute(
            ['a' => $this->q('10.00'), 'b' => $this->q('2.50')],
            $this->q('12.50'),
            18,
            2,
        );
        self::assertSame('1000', $result['a']);
        self::assertSame('250', $result['b']);
    }

    public function testLargestRemainderDistributesDust(): void
    {
        // 100.00 split into 1/3 and 2/3; largest-remainder gives B the extra cent.
        $result = $this->service->distribute(
            ['a' => $this->q('33.333333333333333333'), 'b' => $this->q('66.666666666666666667')],
            $this->q('100.00'),
            18,
            2,
        );
        self::assertSame('3333', $result['a']);
        self::assertSame('6667', $result['b']);
        self::assertSame('10000', array_reduce($result, static fn (string $total, string $amount): string => \Brick\Math\BigInteger::of($total)->plus($amount)->toBase(10), '0'));
    }

    public function testEqualRemaindersBreakByKey(): void
    {
        // 2.00 split into two 1.00 halves => no residual; tie breaks by key for rank but amounts equal.
        $result = $this->service->distribute(
            ['z' => $this->q('1.00'), 'a' => $this->q('1.00')],
            $this->q('2.00'),
            18,
            2,
        );
        self::assertSame('100', $result['z']);
        self::assertSame('100', $result['a']);
    }

    public function testResidualFullyAssigned(): void
    {
        // 1.00 split into three thirds => floors to 33 each, residual 1 to first by order.
        $third = $this->q('0.333333333333333333');
        $result = $this->service->distribute(
            ['a' => $third, 'b' => $third, 'c' => $third],
            $this->q('1.00'),
            18,
            2,
        );
        self::assertSame('34', $result['a']);
        self::assertSame('33', $result['b']);
        self::assertSame('33', $result['c']);
        self::assertSame('100', array_reduce($result, static fn (string $total, string $amount): string => \Brick\Math\BigInteger::of($total)->plus($amount)->toBase(10), '0'));
    }

    public function testRejectsAllocationExceedingFunding(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->distribute(
            ['a' => $this->q('120.00')],
            $this->q('100.00'),
            18,
            2,
        );
    }
}
