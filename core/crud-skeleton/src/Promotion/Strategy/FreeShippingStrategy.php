<?php

declare(strict_types=1);

namespace App\Promotion\Strategy;

use App\Promotion\Service\Dsl\AstNode;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('promotion.strategy')]
final class FreeShippingStrategy implements PromotionStrategyInterface
{
    public static function supportedType(): string
    {
        return 'free_shipping';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(AstNode $action, PriceCalculationContext $context, array $config): void
    {
        $context->meta['freeShipping'] = true;
    }
}
