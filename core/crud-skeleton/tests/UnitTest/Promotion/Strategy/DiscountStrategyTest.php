<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\DiscountStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DiscountStrategyTest extends TestCase
{
    private DiscountStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new DiscountStrategy();
    }

    public function testSupportedType(): void
    {
        self::assertSame('discount', DiscountStrategy::supportedType());
    }

    public function testApplyWithRate(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 80]);

        $this->strategy->apply($action, $context, []);

        // 20% off: 50000 * (100-80) / 100 = 10000, new total = 50000 - 10000 = 40000
        self::assertSame(40000, $context->totalAmount);
    }

    public function testApplyWithRateAndMaxCap(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 200000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 50, 'maxCap' => 50.00]);

        $this->strategy->apply($action, $context, []);

        // 50% off: 200000 * 50 / 100 = 100000
        // cap in cents: 50.00 * 100 = 5000, discount capped at 5000
        // new total = 200000 - 5000 = 195000
        self::assertSame(195000, $context->totalAmount);
    }

    public function testApplyWithMaxCapBelowDiscount(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 100000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 80, 'maxCap' => 10.00]);

        $this->strategy->apply($action, $context, []);

        // 20% off = 100000 * 20 / 100 = 20000, cap = 10.00 * 100 = 1000, use cap
        // new total = 100000 - 1000 = 99000
        self::assertSame(99000, $context->totalAmount);
    }

    public function testApplyWithMaxCapAboveDiscount(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 90, 'maxCap' => 50.00]);

        $this->strategy->apply($action, $context, []);

        // 10% off = 50000 * 10 / 100 = 5000, cap = 5000, not > cap so use discount
        // new total = 50000 - 5000 = 45000
        self::assertSame(45000, $context->totalAmount);
    }

    public function testApplyWithConfigRateRef(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 'config.discount_rate']);

        $this->strategy->apply($action, $context, ['discount_rate' => 70]);

        // 30% off: 50000 * 30 / 100 = 15000, new total = 35000
        self::assertSame(35000, $context->totalAmount);
    }

    public function testApplyToConfiguredSpecificationIds(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 25000;
        $context->items = [
            ['specificationId' => 10, 'price' => 10000],
            ['specificationId' => 20, 'price' => 15000],
        ];

        $action = new AstNode('action_discount', ['target' => 'items', 'rate' => 'config.rate']);
        $this->strategy->apply($action, $context, ['rate' => 90, 'specification_ids' => [10]]);

        self::assertSame(9000, $context->items[0]['price']);
        self::assertSame(15000, $context->items[1]['price']);
        self::assertSame(24000, $context->totalAmount);
    }

    public function testApplyWithMissingConfigRate(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 'config.missing']);

        $this->strategy->apply($action, $context, []);

        // config.missing resolves to 0, rate=0 means 100% off
        // 50000 * 100 / 100 = 50000, new total = 0
        self::assertSame(0, $context->totalAmount);
    }

    public function testApplyDoesNotGoNegative(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 1000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 1]);

        $this->strategy->apply($action, $context, []);

        // 99% off: 1000 * 99 / 100 = 990, new total = 10
        self::assertSame(10, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testApplyWithNonNumericRateDefaultsToZeroRate(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 10000;

        $action = new AstNode('action_discount', ['target' => 'order', 'rate' => 'invalid_string']);

        $this->strategy->apply($action, $context, []);

        // resolveValue returns 0 for non-numeric, non-config strings → 100% discount → total = 0
        self::assertSame(0, $context->totalAmount);
    }
}
