<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service\Dsl;

use App\Promotion\Service\Dsl\AstNode;
use App\Promotion\Service\Dsl\Evaluator;
use App\Promotion\Strategy\PromotionStrategyInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    private PriceCalculationContext $context;
    private array $config;

    protected function setUp(): void
    {
        $this->context = new PriceCalculationContext([]);
        $this->context->totalAmount = 50000; // cents
        $this->context->items = [
            ['unitPrice' => 25.00, 'quantity' => 2, 'specificationId' => 10, 'tags' => ['new', 'sale']],
        ];
        $this->config = ['threshold' => 100.00, 'vip_level' => 'gold', 'store_open' => true];
    }

    private function makeCondition(string $op, mixed $leftValue, mixed $rightValue): AstNode
    {
        return new AstNode('condition', [
            'op' => $op,
            'left' => new AstNode('path', ['value' => $leftValue]),
            'right' => new AstNode('literal', ['value' => $rightValue]),
        ]);
    }

    private function makeLiteralCondition(string $op, mixed $leftValue, mixed $rightValue): AstNode
    {
        return new AstNode('condition', [
            'op' => $op,
            'left' => new AstNode('literal', ['value' => $leftValue]),
            'right' => new AstNode('literal', ['value' => $rightValue]),
        ]);
    }

    private function evaluator(array $strategies = []): Evaluator
    {
        return new Evaluator($strategies);
    }

    // ──────────────────────────── conditions: operators ────────────────────────────

    public function testEvaluateGreaterOrEqualTrue(): void
    {
        $cond = $this->makeCondition('>=', 'cart.subtotal', 10000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateGreaterOrEqualFalse(): void
    {
        $cond = $this->makeCondition('>=', 'cart.subtotal', 60000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateLessOrEqualTrue(): void
    {
        $cond = $this->makeCondition('<=', 'cart.subtotal', 50000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateLessOrEqualFalse(): void
    {
        $cond = $this->makeCondition('<=', 'cart.subtotal', 10000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateEqualTrue(): void
    {
        $cond = $this->makeCondition('==', 'cart.subtotal', 50000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateEqualFalse(): void
    {
        $cond = $this->makeCondition('==', 'cart.subtotal', 40000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateNotEqualTrue(): void
    {
        $cond = $this->makeCondition('!=', 'cart.subtotal', 10000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateNotEqualFalse(): void
    {
        $cond = $this->makeCondition('!=', 'cart.subtotal', 50000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateGreaterThanTrue(): void
    {
        $cond = $this->makeCondition('>', 'cart.subtotal', 10000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateGreaterThanFalse(): void
    {
        $cond = $this->makeCondition('>', 'cart.subtotal', 50000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateLessThanTrue(): void
    {
        $cond = $this->makeCondition('<', 'cart.subtotal', 100000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateLessThanFalse(): void
    {
        $cond = $this->makeCondition('<', 'cart.subtotal', 50000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    // ──────────────────────────── conditions: in / includes ────────────────────────────

    public function testEvaluateInTrue(): void
    {
        $left = new AstNode('literal', ['value' => 'gold']);
        $right = new AstNode('literal', ['value' => ['gold', 'platinum']]);
        $cond = new AstNode('condition', ['op' => 'in', 'left' => $left, 'right' => $right]);

        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateInFalse(): void
    {
        $left = new AstNode('literal', ['value' => 'bronze']);
        $right = new AstNode('literal', ['value' => ['gold', 'platinum']]);
        $cond = new AstNode('condition', ['op' => 'in', 'left' => $left, 'right' => $right]);

        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateInNonArrayRight(): void
    {
        $left = new AstNode('literal', ['value' => 'gold']);
        $right = new AstNode('literal', ['value' => 'gold']);
        $cond = new AstNode('condition', ['op' => 'in', 'left' => $left, 'right' => $right]);

        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateIncludesArrayTrue(): void
    {
        $left = new AstNode('literal', ['value' => ['a', 'b', 'c']]);
        $right = new AstNode('literal', ['value' => 'b']);
        $cond = new AstNode('condition', ['op' => 'includes', 'left' => $left, 'right' => $right]);

        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateIncludesArrayFalse(): void
    {
        $left = new AstNode('literal', ['value' => ['a', 'b']]);
        $right = new AstNode('literal', ['value' => 'c']);
        $cond = new AstNode('condition', ['op' => 'includes', 'left' => $left, 'right' => $right]);

        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateIncludesStringTrue(): void
    {
        $left = new AstNode('literal', ['value' => 'hello world']);
        $right = new AstNode('literal', ['value' => 'world']);
        $cond = new AstNode('condition', ['op' => 'includes', 'left' => $left, 'right' => $right]);

        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateIncludesStringFalse(): void
    {
        $left = new AstNode('literal', ['value' => 'hello']);
        $right = new AstNode('literal', ['value' => 'xyz']);
        $cond = new AstNode('condition', ['op' => 'includes', 'left' => $left, 'right' => $right]);

        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    // ──────────────────────────── conditions: and / or / not ────────────────────────────

    public function testEvaluateAndAllTrue(): void
    {
        $node = new AstNode('and');
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 10000);
        $node->children[] = $this->makeCondition('<', 'cart.subtotal', 100000);

        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateAndOneFalse(): void
    {
        $node = new AstNode('and');
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 10000);
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 100000);

        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateAndFirstFalseShortCircuits(): void
    {
        $node = new AstNode('and');
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 100000);

        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateOrOneTrue(): void
    {
        $node = new AstNode('or');
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 100000);
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 10000);

        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateOrAllFalse(): void
    {
        $node = new AstNode('or');
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 100000);
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 200000);

        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvaluateNotTrue(): void
    {
        $node = new AstNode('not');
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 100000);

        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvaluateNotFalse(): void
    {
        $node = new AstNode('not');
        $node->children[] = $this->makeCondition('>=', 'cart.subtotal', 10000);

        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertFalse($result);
    }

    // ──────────────────────────── path resolution ────────────────────────────

    public function testResolveCartSubtotal(): void
    {
        $cond = $this->makeCondition('==', 'cart.subtotal', 50000);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveCartItemsCount(): void
    {
        $cond = $this->makeCondition('==', 'cart.items.count', 1);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveConfigValue(): void
    {
        $cond = $this->makeCondition('==', 'config.threshold', 100.00);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveConfigBooleanTrue(): void
    {
        $cond = $this->makeCondition('==', 'config.store_open', true);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveConfigMissingKey(): void
    {
        $cond = $this->makeCondition('==', 'config.missing', null);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveItemPrice(): void
    {
        $cond = $this->makeCondition('==', 'item.price', 25.00);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveItemQuantity(): void
    {
        $cond = $this->makeCondition('==', 'item.quantity', 2);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveItemSpecId(): void
    {
        $cond = $this->makeCondition('==', 'item.spec.id', 10);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveItemTags(): void
    {
        $cond = $this->makeCondition('==', 'item.tags', ['new', 'sale']);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolveUnknownPath(): void
    {
        $cond = $this->makeCondition('==', 'unknown.path', null);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    // ──────────────────────────── empty items ────────────────────────────

    public function testResolveItemWithEmptyItemsReturnsZero(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->items = [];

        $cond = $this->makeCondition('==', 'item.price', 0);
        $result = $this->evaluator()->evaluateCondition($cond, $context, $this->config);
        self::assertTrue($result);
    }

    // ──────────────────────────── action execution ────────────────────────────

    public function testExecuteActionsDispatchesToStrategy(): void
    {
        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 10.00]);

        $strategy = new class implements PromotionStrategyInterface {
            public bool $called = false;
            public ?AstNode $receivedAction = null;
            public ?PriceCalculationContext $receivedContext = null;
            public array $receivedConfig = [];

            public static function supportedType(): string
            {
                return 'discount';
            }

            public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
            {
                $this->called = true;
                $this->receivedAction = $action;
                $this->receivedContext = $context;
                $this->receivedConfig = $config;
            }
        };

        $evaluator = $this->evaluator([$strategy]);
        $evaluator->executeActions([$action], 'discount', $this->context, $this->config);

        self::assertTrue($strategy->called);
        self::assertSame($action, $strategy->receivedAction);
        self::assertSame($this->context, $strategy->receivedContext);
        self::assertSame($this->config, $strategy->receivedConfig);
    }

    public function testExecuteActionsMultipleActions(): void
    {
        $calls = [];
        $strategy = new class($calls) implements PromotionStrategyInterface {
            public function __construct(private array &$callsRef) {}
            public static function supportedType(): string { return 'discount'; }
            public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
            {
                $this->callsRef[] = $action;
            }
        };

        $action1 = new AstNode('action_discount', ['target' => 'order', 'value' => 10.00]);
        $action2 = new AstNode('action_discount', ['target' => 'order', 'value' => 5.00]);

        $evaluator = $this->evaluator([$strategy]);
        $evaluator->executeActions([$action1, $action2], 'discount', $this->context, $this->config);

        self::assertCount(2, $calls);
        self::assertSame($action1, $calls[0]);
        self::assertSame($action2, $calls[1]);
    }

    public function testExecuteActionsUnknownTypeThrows(): void
    {
        $action = new AstNode('action_discount', ['target' => 'order', 'value' => 10.00]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No strategy found for promotion type 'unknown_type'");

        $this->evaluator()->executeActions([$action], 'unknown_type', $this->context, $this->config);
    }

    public function testEvaluateDefaultTypeReturnsTrue(): void
    {
        $node = new AstNode('unknown_type');
        $result = $this->evaluator()->evaluateCondition($node, $this->context, $this->config);
        self::assertTrue($result);
    }

    // ──────────────────── edge cases ────────────────────

    public function testEvalInWithNonArrayReturnsFalse(): void
    {
        $cond = new AstNode('condition', [
            'op' => 'in',
            'left' => new AstNode('literal', ['value' => 42]),
            'right' => new AstNode('literal', ['value' => 'not-an-array']),
        ]);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testEvalIncludesWithArrayReturnsTrue(): void
    {
        $cond = new AstNode('condition', [
            'op' => 'includes',
            'left' => new AstNode('literal', ['value' => ['a', 'b']]),
            'right' => new AstNode('literal', ['value' => 'b']),
        ]);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testEvalIncludesWithNonArrayNonStringReturnsFalse(): void
    {
        $cond = new AstNode('condition', [
            'op' => 'includes',
            'left' => new AstNode('literal', ['value' => 42]),
            'right' => new AstNode('literal', ['value' => 'x']),
        ]);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertFalse($result);
    }

    public function testResolvePathWithItemWhenItemsEmpty(): void
    {
        $this->context->items = [];
        $cond = new AstNode('condition', [
            'op' => '>=',
            'left' => new AstNode('path', ['value' => 'item.price']),
            'right' => new AstNode('literal', ['value' => 0.0]),
        ]);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }

    public function testResolvePathWithUserTagsNonUserObject(): void
    {
        $this->context->user = new \stdClass();
        $cond = new AstNode('condition', [
            'op' => 'includes',
            'left' => new AstNode('path', ['value' => 'user.tags']),
            'right' => new AstNode('literal', ['value' => 'vip']),
        ]);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        // user.tags returns null for non-User, includes(null, 'vip') = false
        self::assertFalse($result);
    }

    public function testResolveOperandWithRawNonAstNodeValue(): void
    {
        $cond = new AstNode('condition', [
            'op' => '>=',
            'left' => 100,
            'right' => 50,
        ]);
        $result = $this->evaluator()->evaluateCondition($cond, $this->context, $this->config);
        self::assertTrue($result);
    }
}
