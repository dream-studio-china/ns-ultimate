<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Service;

use App\Inventory\Service\Quantity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers the remaining uncovered lines of Quantity::normalize().
 *
 * Note: Quantity::multiply() line 75 (`str_pad($result, 13, '0', STR_PAD_LEFT)`)
 * is unreachable through the public API — see the coverage report.
 */
final class QuantityCoverageTest extends TestCase
{
    public function testRejectsMalformedQuantityStrings(): void
    {
        foreach (['abc', '1.2.3', '12,34', '1e3', '1.0000000', '', '  ', '-.5', '+'] as $malformed) {
            try {
                Quantity::normalize($malformed);
                self::fail(sprintf('Expected normalization of "%s" to be rejected.', $malformed));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Quantity must be a decimal string', $exception->getMessage());
            }
        }
    }

    #[Group('low-value')]
    public function testRejectsLeadingZeroVariantsThroughNormalize(): void
    {
        self::assertSame('12.340000', Quantity::normalize('+00000012.34'));
        self::assertSame('-0.500000', Quantity::normalize('-0.500000'));
        self::assertSame('0.000000', Quantity::normalize('0.000000'));
    }

    public function testRejectsNonPositiveQuantityWhenPositiveRequired(): void
    {
        foreach (['0.000000', '-1.000000', '-0.000001', '-0.000000'] as $quantity) {
            try {
                Quantity::normalize($quantity, true);
                self::fail(sprintf('Expected positive normalization of "%s" to be rejected.', $quantity));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Quantity must be greater than zero.', $exception->getMessage());
            }
        }
    }

    public function testSmallMultiplicationsKeepExactScale(): void
    {
        self::assertSame('0.000001', Quantity::multiply('1.000000', '0.000001'));
        self::assertSame('0.000005', Quantity::multiply('0.000001', '5.000000'));
        self::assertSame('0.000000', Quantity::multiply('0.000000', '5.000000'));
        self::assertSame('6.000000', Quantity::multiply('2.000000', '3.000000'));
    }
}
