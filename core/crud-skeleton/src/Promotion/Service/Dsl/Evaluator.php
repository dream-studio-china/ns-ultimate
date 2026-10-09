<?php

declare(strict_types=1);

namespace App\Promotion\Service\Dsl;

use App\Promotion\Strategy\PromotionStrategyInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;

class Evaluator
{
    /** @param PromotionStrategyInterface[] $strategies */
    public function __construct(
        private readonly array $strategies = [],
    ) {}

    /**
     * Evaluate a condition node against the context.
     * @param array<string, mixed> $config
     */
    public function evaluateCondition(AstNode $condition, PriceCalculationContext $context, array $config): bool
    {
        $type = $condition->type;

        return match ($type) {
            'condition' => $this->evalSingle($condition, $context, $config),
            'and' => $this->evalAnd($condition, $context, $config),
            'or' => $this->evalOr($condition, $context, $config),
            'not' => $this->evalNot($condition, $context, $config),
            default => true,
        };
    }

    /**
     * Execute action nodes against the context.
     * @param AstNode[] $actions
     * @param array<string, mixed> $config
     */
    public function executeActions(array $actions, string $promotionType, PriceCalculationContext $context, array $config): void
    {
        $strategy = $this->findStrategy($promotionType);

        foreach ($actions as $action) {
            $strategy->apply($action, $context, $config);
        }
    }

    /** @param array<string, mixed> $config */
    private function evalSingle(AstNode $cond, PriceCalculationContext $context, array $config): bool
    {
        $left = $this->resolveOperand($cond->data['left'] ?? null, $context, $config);
        $op = $cond->data['op'] ?? '>=';
        $right = $this->resolveOperand($cond->data['right'] ?? null, $context, $config);

        return match ($op) {
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            '==' => $left == $right,
            '!=' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            'in' => $this->evalIn($left, $right),
            'includes' => $this->evalIncludes($left, $right),
            default => false,
        };
    }

    private function evalIn(mixed $left, mixed $right): bool
    {
        if (!is_array($right)) {
            return false;
        }
        return in_array($left, $right, true);
    }

    private function evalIncludes(mixed $left, mixed $right): bool
    {
        if (is_array($left)) {
            return in_array($right, $left, true);
        }
        if (is_string($left) && is_string($right)) {
            return str_contains($left, $right);
        }
        return false;
    }

    /** @param array<string, mixed> $config */
    private function evalAnd(AstNode $node, PriceCalculationContext $context, array $config): bool
    {
        foreach ($node->children as $child) {
            if (!$this->evaluateCondition($child, $context, $config)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $config */
    private function evalOr(AstNode $node, PriceCalculationContext $context, array $config): bool
    {
        foreach ($node->children as $child) {
            if ($this->evaluateCondition($child, $context, $config)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $config */
    private function evalNot(AstNode $node, PriceCalculationContext $context, array $config): bool
    {
        foreach ($node->children as $child) {
            if ($this->evaluateCondition($child, $context, $config)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $config */
    private function resolveOperand(mixed $operand, PriceCalculationContext $context, array $config): mixed
    {
        if ($operand === null) {
            return null;
        }

        if ($operand instanceof AstNode) {
            if ($operand->type === 'literal') {
                return $operand->data['value'] ?? null;
            }
            if ($operand->type === 'path') {
                return $this->resolvePath((string) $operand->data['value'], $context, $config);
            }
        }

        return $operand;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolvePath(string $path, PriceCalculationContext $context, array $config): mixed
    {
        $parts = explode('.', $path);

        // config.xxx
        if ($parts[0] === 'config') {
            return $config[$parts[1] ?? ''] ?? null;
        }

        // cart.subtotal, cart.items.count
        if ($parts[0] === 'cart') {
            if (($parts[1] ?? '') === 'subtotal') {
                return $context->totalAmount;
            }
            if (($parts[1] ?? '') === 'items' && ($parts[2] ?? '') === 'count') {
                return count($context->items);
            }
        }

        // user.level, user.tags
        if ($parts[0] === 'user') {
            $user = $context->user;
            if (($parts[1] ?? '') === 'id' && $user !== null && method_exists($user, 'getId')) {
                return $user->getId();
            }
            if (($parts[1] ?? '') === 'level' && $user instanceof \App\Identity\Entity\User) {
                return $user->getProfile()?->getLevel() ?? '';
            }
            if (($parts[1] ?? '') === 'tags' && $user !== null && method_exists($user, 'getTags')) {
                return $user->getTags();
            }
        }

        // item.price, item.quantity, item.spec.id, item.tags
        if ($parts[0] === 'item') {
            $item = $context->items[0] ?? [];
            if (($parts[1] ?? '') === 'price') {
                return (float) ($item['unitPrice'] ?? 0);
            }
            if (($parts[1] ?? '') === 'quantity') {
                return (int) ($item['quantity'] ?? 0);
            }
            if (($parts[1] ?? '') === 'spec' && ($parts[2] ?? '') === 'id') {
                return $item['specificationId'] ?? null;
            }
            if (($parts[1] ?? '') === 'tags') {
                return $item['tags'] ?? [];
            }
        }

        return null;
    }

    private function findStrategy(string $type): PromotionStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy::supportedType() === $type) {
                return $strategy;
            }
        }
        throw new \RuntimeException("No strategy found for promotion type '{$type}'");
    }
}
