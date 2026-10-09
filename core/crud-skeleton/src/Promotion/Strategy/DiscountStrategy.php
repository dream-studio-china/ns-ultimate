<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
final class DiscountStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'discount';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $rate = $this->resolveValue($action->data['rate'] ?? $action->data['value'] ?? 100, $config);

        if (($action->data['target'] ?? 'order') === 'items') {
            $this->applyToMatchingItems($rate, $context, $config);
            return;
        }

        $cap = isset($action->data['maxCap']) ? (int) ($action->data['maxCap'] * 100) : null;

        $discount = (int) ($context->totalAmount * (100 - $rate) / 100);

        if ($cap !== null && $discount > $cap) {
            $discount = $cap;
        }

        $context->totalAmount = max(0, $context->totalAmount - $discount);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyToMatchingItems(float $rate, PriceCalculationContext $context, array $config): void
    {
        $specificationIds = array_map('intval', $config['specification_ids'] ?? []);

        foreach ($context->items as &$item) {
            $specificationId = (int) ($item['specificationId'] ?? 0);
            if ($specificationIds !== [] && !in_array($specificationId, $specificationIds, true)) {
                continue;
            }

            $price = (int) ($item['price'] ?? 0);
            $discountedPrice = (int) round($price * $rate / 100);
            $context->totalAmount -= $price - $discountedPrice;
            $item['price'] = $discountedPrice;
        }
        unset($item);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveValue(mixed $value, array $config): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && str_starts_with($value, 'config.')) {
            $key = substr($value, 7);
            return (float) ($config[$key] ?? 0);
        }
        return 0.0;
    }
}
