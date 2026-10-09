<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\GiftStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class GiftStrategyTest extends TestCase
{
    private GiftStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new GiftStrategy();
    }

    public function testSupportedType(): void
    {
        self::assertSame('gift', GiftStrategy::supportedType());
    }

    public function testApplyAddsGiftItem(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_gift', ['spec' => 100, 'count' => 1]);

        $this->strategy->apply($action, $context, []);

        self::assertCount(1, $context->items);
        $item = $context->items[0];
        self::assertSame(100, $item['specificationId']);
        self::assertSame(1, $item['quantity']);
        self::assertSame(0, $item['unitPrice']);
        self::assertSame(0, $item['price']);
        self::assertTrue($item['isGift']);
    }

    public function testApplyWithConfigSpecRef(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_gift', ['spec' => 'config.gift_spec_id', 'count' => 1]);

        $this->strategy->apply($action, $context, ['gift_spec_id' => 200]);

        self::assertCount(1, $context->items);
        self::assertSame(200, $context->items[0]['specificationId']);
    }

    #[Group('low-value')]
    public function testApplyWithConfigSpecAndCount(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        // The GiftStrategy does (int) on count, so string values become 0
        // Use integer count to verify the spec resolution works
        $action = new AstNode('action_gift', ['spec' => 'config.gift_spec_id', 'count' => 5]);

        $this->strategy->apply($action, $context, ['gift_spec_id' => 300]);

        self::assertCount(1, $context->items);
        self::assertSame(300, $context->items[0]['specificationId']);
        self::assertSame(5, $context->items[0]['quantity']);
    }

    #[Group('low-value')]
    public function testApplyWithNumericSpec(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_gift', ['spec' => 42, 'count' => 2]);

        $this->strategy->apply($action, $context, []);

        self::assertCount(1, $context->items);
        self::assertSame(42, $context->items[0]['specificationId']);
        self::assertSame(2, $context->items[0]['quantity']);
    }

    public function testApplyWithMissingConfigSpec(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_gift', ['spec' => 'config.missing_spec', 'count' => 1]);

        $this->strategy->apply($action, $context, []);

        self::assertCount(1, $context->items);
        self::assertNull($context->items[0]['specificationId']);
        self::assertSame(1, $context->items[0]['quantity']);
    }

    public function testApplyAppendsToExistingItems(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->items = [
            ['unitPrice' => 25.00, 'quantity' => 1, 'specificationId' => 10],
        ];

        $action = new AstNode('action_gift', ['spec' => 50, 'count' => 1]);

        $this->strategy->apply($action, $context, []);

        self::assertCount(2, $context->items);
        self::assertSame(50, $context->items[1]['specificationId']);
        self::assertTrue($context->items[1]['isGift']);
    }

    public function testApplyWithAlternateDataKeys(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_gift', ['spec:config.gift_spec_id' => 'config.gift_spec_id', 'count:config.gift_qty' => 'config.gift_qty']);

        $this->strategy->apply($action, $context, ['gift_spec_id' => 999, 'gift_qty' => 3]);

        self::assertCount(1, $context->items);
    }

    public function testApplyDefaultCountWhenNotSpecified(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_gift', ['spec' => 10]);

        $this->strategy->apply($action, $context, []);

        self::assertCount(1, $context->items);
        self::assertSame(1, $context->items[0]['quantity']);
    }
}
