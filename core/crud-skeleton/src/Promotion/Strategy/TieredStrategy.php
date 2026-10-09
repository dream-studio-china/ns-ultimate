<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
final class TieredStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'tiered';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $subt = $context->totalAmount;
        $bestDiscount = 0;

        // Apply the best-matching tier (highest from that doesn't exceed subtotal)
        foreach ($action->children as $tier) {
            $from = (int) (($tier->data['from'] ?? 0) * 100); // cents
            $less = (int) (($tier->data['less'] ?? 0) * 100); // cents

            if ($subt >= $from && $less > $bestDiscount) {
                $bestDiscount = $less;
            }
        }

        $context->totalAmount = max(0, $context->totalAmount - $bestDiscount);
    }
}
