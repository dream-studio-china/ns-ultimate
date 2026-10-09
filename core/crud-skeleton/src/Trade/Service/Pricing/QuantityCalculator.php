<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trade.price_calculator')]
class QuantityCalculator implements PriceCalculatorInterface
{
    public static function getPriority(): int
    {
        return 50;
    }

    public function calculate(PriceCalculationContext $context): void
    {
        foreach ($context->items as &$item) {
            $item['price'] = $item['unitPrice'] * $item['quantity'];
        }
        unset($item);
    }
}
