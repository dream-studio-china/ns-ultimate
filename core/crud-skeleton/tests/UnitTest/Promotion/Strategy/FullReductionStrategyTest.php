<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Strategy\FullReductionStrategy;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class FullReductionStrategyTest extends TestCase
{
    private FullReductionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new FullReductionStrategy();
    }

    public function testSupportedType(): void
    {
        self::assertSame('full_reduction', FullReductionStrategy::supportedType());
    }

    public function testApplyReducesTotalAmount(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 20.00]);

        $this->strategy->apply($action, $context, ['threshold' => 200.00]);

        self::assertSame(48000, $context->totalAmount);
    }

    public function testApplyZeroValue(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 0]);

        $this->strategy->apply($action, $context, []);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyDoesNotGoNegative(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 1000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 50.00]);

        $this->strategy->apply($action, $context, []);

        self::assertSame(0, $context->totalAmount);
    }

    public function testApplyWithConfigRefValue(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 'config.discount_amount']);

        $this->strategy->apply($action, $context, ['discount_amount' => 15.50]);

        self::assertSame(48450, $context->totalAmount);
    }

    public function testApplyWithMissingConfigRef(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 'config.missing']);

        $this->strategy->apply($action, $context, []);

        self::assertSame(50000, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testApplyWithNonNumericString(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 'not_a_number']);

        $this->strategy->apply($action, $context, []);

        self::assertSame(50000, $context->totalAmount);
    }

    public function testApplyWithIntegerValue(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 30]);

        $this->strategy->apply($action, $context, []);

        // $value = 30.0, amount = (int)(30.0 * 100) = 3000, 50000 - 3000 = 47000
        self::assertSame(47000, $context->totalAmount);
    }

    #[Group('low-value')]
    public function testApplyWithoutConfig(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 10.00]);

        $this->strategy->apply($action, $context, []);

        self::assertSame(49000, $context->totalAmount);
    }
}
