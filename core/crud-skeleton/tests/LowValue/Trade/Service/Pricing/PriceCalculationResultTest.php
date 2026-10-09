<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Trade\Service\Pricing;


use PHPUnit\Framework\Attributes\Group;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class PriceCalculationResultTest extends TestCase
{
    public function testConstructorInitializesFields(): void
    {
        $result = new PriceCalculationResult(1000, 'CNY', [], ['promotion' => ['applied' => []]]);

        self::assertSame(1000, $result->totalAmount);
        self::assertSame('CNY', $result->currency);
        self::assertSame([], $result->items);
        self::assertSame(['promotion' => ['applied' => []]], $result->meta);
    }

    public function testMetaDefaultsToEmptyArray(): void
    {
        $result = new PriceCalculationResult(1000, 'CNY', []);

        self::assertSame([], $result->meta);
    }

    public function testFromContextCopiesMeta(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 5000;
        $context->currency = 'USD';
        $context->items = [['unitPrice' => 100, 'quantity' => 1]];
        $context->meta = ['promotion' => ['inner' => [['promotionId' => 1]]]];

        $result = PriceCalculationResult::fromContext($context);

        self::assertSame(5000, $result->totalAmount);
        self::assertSame('USD', $result->currency);
        self::assertCount(1, $result->items);
        self::assertSame(1, $result->meta['promotion']['inner'][0]['promotionId']);
    }

    public function testFromContextWithEmptyMeta(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 1000;

        $result = PriceCalculationResult::fromContext($context);

        self::assertSame([], $result->meta);
    }

    public function testFromContextPreservesMultipleMetaKeys(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 5000;
        $context->meta = [
            'promotion' => ['inner' => []],
            'coupon' => ['code' => 'ABC123'],
        ];

        $result = PriceCalculationResult::fromContext($context);

        self::assertArrayHasKey('promotion', $result->meta);
        self::assertArrayHasKey('coupon', $result->meta);
        self::assertSame('ABC123', $result->meta['coupon']['code']);
    }
}
