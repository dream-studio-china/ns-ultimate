<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\TieredStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TieredStrategyTest extends TestCase
{
    private TieredStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TieredStrategy();
    }

    public function testSupportedType(): void
    {
        self::assertSame('tiered', TieredStrategy::supportedType());
    }

    public function testApplyPicksHighestMatchingTier(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', ['from' => 10.00, 'less' => 5.00]),
            new AstNode('tier', ['from' => 20.00, 'less' => 12.00]),
            new AstNode('tier', ['from' => 50.00, 'less' => 30.00]),
        ]);

        $this->strategy->apply($action, $context, []);

        // subtotal 50000 >= from:5000 => highest discount = 3000 (30.00 * 100)
        // 50000 - 3000 = 47000
        self::assertSame(47000, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testApplyWithTiersBelowSubtotal(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 1500;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', ['from' => 1.00, 'less' => 0.50]),
            new AstNode('tier', ['from' => 5.00, 'less' => 2.00]),
            new AstNode('tier', ['from' => 10.00, 'less' => 5.00]),
        ]);

        $this->strategy->apply($action, $context, []);

        // subtotal 1500 >= from:100, from:500, from:1000 (all)
        // highest less = 500 (5.00 * 100)
        // 1500 - 500 = 1000
        self::assertSame(1000, $context->totalAmount);
    }

    public function testApplyPicksHigherLessEvenIfFromIsSmaller(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 100000;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', ['from' => 10.00, 'less' => 50.00]),
            new AstNode('tier', ['from' => 50.00, 'less' => 20.00]),
        ]);

        $this->strategy->apply($action, $context, []);

        // subtotal 100000 matches both: from=1000 (10.00*100), from=5000 (50.00*100)
        // highest less = 5000 (50.00 * 100)
        // 100000 - 5000 = 95000
        self::assertSame(95000, $context->totalAmount);
    }

    public function testApplyWhenNoTiersMatch(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 500;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', ['from' => 10.00, 'less' => 5.00]),
            new AstNode('tier', ['from' => 50.00, 'less' => 30.00]),
        ]);

        $this->strategy->apply($action, $context, []);

        // 500 < 1000 (10.00*100) and < 5000 (50.00*100), no tier matches
        // bestDiscount stays at 0
        self::assertSame(500, $context->totalAmount);
    }

    public function testApplyWithNoChildren(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_tiered', [], []);

        $this->strategy->apply($action, $context, []);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyDoesNotGoNegative(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 200;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', ['from' => 1.00, 'less' => 50.00]),
        ]);

        $this->strategy->apply($action, $context, []);

        // 200 >= 100 (1.00*100), discount = 5000 (50.00*100)
        // max(0, 200 - 5000) = 0
        self::assertSame(0, $context->totalAmount);
    }

    public function testApplyUsesDefaultFromAndLess(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', []),
        ]);

        $this->strategy->apply($action, $context, []);

        // from defaults to 0, less defaults to 0
        // 50000 >= 0 => bestDiscount = 0
        // total = 50000 - 0 = 50000
        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplySkipsTierWhenFromExceedsSubtotal(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 2500;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', ['from' => 10.00, 'less' => 5.00]),
            new AstNode('tier', ['from' => 50.00, 'less' => 30.00]),
            new AstNode('tier', ['from' => 100.00, 'less' => 80.00]),
        ]);

        $this->strategy->apply($action, $context, []);

        // 2500 >= 1000 (10*100), >= 2500 >= 5000? NO, 2500 < 10000? YES
        // matching: first tier (from=1000), second tier from=5000 is not <= 2500
        // bestDiscount = 500 (5.00 * 100)
        // 2500 - 500 = 2000
        self::assertSame(2000, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testApplyPicksSecondTierWhenFirstDoesNotMatch(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 6000;

        $action = new AstNode('action_tiered', [], [
            new AstNode('tier', ['from' => 100.00, 'less' => 50.00]),
            new AstNode('tier', ['from' => 50.00, 'less' => 20.00]),
        ]);

        $this->strategy->apply($action, $context, []);

        // 6000 < 10000 (100*100) => skip first
        // 6000 >= 5000 (50*100) => match, bestDiscount = 2000 (20*100)
        // 6000 - 2000 = 4000
        self::assertSame(4000, $context->totalAmount);
    }
}
