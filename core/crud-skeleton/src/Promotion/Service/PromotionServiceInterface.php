<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Core\Service\BaseServiceInterface;
use App\Promotion\Entity\Promotion;
use App\Trade\Service\Pricing\PriceCalculationContext;

/** @extends BaseServiceInterface<\App\Promotion\Entity\Promotion> */
interface PromotionServiceInterface extends BaseServiceInterface
{
    /**
     * Find all available promotions matching the current context.
     * @param list<int> $excludedIds
     * @return Promotion[]
     */
    public function getAvailable(
        PriceCalculationContext $context,
        ?int $phase = null,
        array $excludedIds = []
    ): array;

    /**
     * Get the top-ranked available promotion, or null.
     * @param list<int> $excludedIds
     */
    public function getFirstAvailable(
        PriceCalculationContext $context,
        ?int $phase = null,
        array $excludedIds = []
    ): ?Promotion;

    /**
     * Apply a promotion's actions to mutate the context.
     */
    public function apply(
        Promotion $promotion,
        PriceCalculationContext $context
    ): void;
}
