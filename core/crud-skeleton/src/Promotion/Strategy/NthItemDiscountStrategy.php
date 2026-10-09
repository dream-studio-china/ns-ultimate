<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
final class NthItemDiscountStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'nth_discount';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $position = (int) ($action->data['position'] ?? 0);
        $rate = (float) ($action->data['rate'] ?? 100);

        // Items are stored as quantity groups. Apply the rate to the Nth unit of
        // every eligible group, without changing the catalog unit price snapshot.
        foreach ($context->items as &$item) {
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($position > 0 && $quantity >= $position) {
                $unitPrice = (int) ($item['unitPrice'] ?? 0);
                $discountedPrice = (int) round($unitPrice * $rate / 100);
                $discount = max(0, $unitPrice - $discountedPrice);
                $item['price'] = max(0, (int) ($item['price'] ?? 0) - $discount);
                $context->totalAmount = max(0, $context->totalAmount - $discount);
            }
        }
        unset($item);
    }
}
