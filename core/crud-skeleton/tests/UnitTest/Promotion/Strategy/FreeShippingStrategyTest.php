<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\FreeShippingStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

final class FreeShippingStrategyTest extends TestCase
{
    private FreeShippingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new FreeShippingStrategy();
    }

    public function testSupportedType(): void
    {
        self::assertSame('free_shipping', FreeShippingStrategy::supportedType());
    }

    public function testApplySetsFreeShippingMeta(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_free_shipping', []);

        $this->strategy->apply($action, $context, []);

        self::assertTrue($context->meta['freeShipping']);
    }

    public function testApplyPreservesExistingMeta(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->meta = ['existingKey' => 'value'];

        $action = new AstNode('action_free_shipping', []);

        $this->strategy->apply($action, $context, []);

        self::assertTrue($context->meta['freeShipping']);
        self::assertSame('value', $context->meta['existingKey']);
    }
}
