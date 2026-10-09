<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
final class GiftStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'gift';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $specRef = $action->data['spec'] ?? $action->data['spec:config.gift_spec_id'] ?? null;
        $count = (int) ($action->data['count'] ?? $action->data['count:config.gift_qty'] ?? 1);

        // Resolve spec reference from config
        $specId = null;
        if (is_string($specRef) && str_starts_with($specRef, 'config.')) {
            $key = substr($specRef, 7);
            $specId = $config[$key] ?? null;
        } elseif (is_numeric($specRef)) {
            $specId = (int) $specRef;
        }

        $context->items[] = [
            'specificationId' => $specId,
            'quantity' => $count,
            'unitPrice' => 0,
            'price' => 0,
            'isGift' => true,
        ];
    }
}
