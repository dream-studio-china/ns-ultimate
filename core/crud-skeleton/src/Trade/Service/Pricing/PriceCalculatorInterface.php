<?php

declare(strict_types=1);

namespace App\Trade\Service\Pricing;

interface PriceCalculatorInterface
{
    public function calculate(PriceCalculationContext $context): void;

    public static function getPriority(): int;
}
