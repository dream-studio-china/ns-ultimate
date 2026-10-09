<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\NthItemDiscountStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

final class NthItemDiscountStrategyTest extends TestCase
{
    public function testSupportedType(): void
    {
        self::assertSame('nth_discount', NthItemDiscountStrategy::supportedType());
    }

    public function testAppliesToTheNthUnitOfEveryQuantityGroup(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['unitPrice' => 10000, 'quantity' => 3, 'price' => 30000],
            ['unitPrice' => 20000, 'quantity' => 1, 'price' => 20000],
        ];

        (new NthItemDiscountStrategy())->apply(
            new AstNode('action_discount', ['position' => 3, 'rate' => 50]),
            $context,
            [],
        );

        // Only one unit in the first group changes from 100.00 to 50.00.
        self::assertSame(25000, $context->items[0]['price']);
        self::assertSame(10000, $context->items[0]['unitPrice']);
        self::assertSame(20000, $context->items[1]['price']);
    }

    public function testDoesNothingWhenTheGroupDoesNotContainNthUnit(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [['unitPrice' => 10000, 'quantity' => 2, 'price' => 20000]];

        (new NthItemDiscountStrategy())->apply(
            new AstNode('action_discount', ['position' => 3, 'rate' => 50]),
            $context,
            [],
        );

        self::assertSame(20000, $context->items[0]['price']);
    }
}
