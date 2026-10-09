<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trade.price_calculator')]
class TotalAggregator implements PriceCalculatorInterface
{
    public static function getPriority(): int
    {
        // Establish the cart subtotal before calculators that apply order-level adjustments.
        return 55;
    }

    public function calculate(PriceCalculationContext $context): void
    {
        $total = 0;
        foreach ($context->items as $item) {
            $total += $item['price'];
        }
        $context->totalAmount = $total;
    }
}
