<?php

declare(strict_types=1);

namespace App\Promotion\Service;

use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Entity\Promotion;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('trade.price_calculator')]
class PromotionCalculator implements PriceCalculatorInterface
{
    private const MAX_ITERATIONS = 20;

    public function __construct(
        private readonly PromotionServiceInterface $promotionService,
    ) {}

    public static function getPriority(): int
    {
        return 60;
    }

    public function calculate(PriceCalculationContext $context): void
    {
        $appliedIds = [];

        $innerApplied = [];

        // Phase INNER: item-level promotions
        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $promotion = $this->getFirstStandardAvailable(
                $context,
                PromotionTemplate::PHASE_INNER,
                $appliedIds,
            );

            if ($promotion === null) {
                break;
            }

            $this->promotionService->apply($promotion, $context);

            // Every promotion instance is applied at most once per quotation.
            $appliedIds[] = (int) $promotion->getId();

            $innerApplied[] = [
                'promotionId' => $promotion->getId(),
                'promotionName' => $promotion->getName(),
                'templateName' => $promotion->getTemplate()?->getName(),
                'type' => $promotion->getTemplate()?->getType(),
                'config' => $promotion->getConfig(),
                'snapshot' => [
                    'totalAmount' => $context->totalAmount,
                    'itemsCount' => count($context->items),
                ],
                'iteration' => $i,
            ];

            if ($promotion->getConflictMode() === 'exclusive') {
                break;
            }

            if ($promotion->getConflictMode() === 'lock_item') {
                // The current context does not expose per-action item targets. Do not
                // allow a lock-item campaign to stack with later campaigns.
                break;
            }
        }

        // Phase OUTER: order-level promotions
        $outerPromotion = $this->getFirstStandardAvailable(
            $context,
            PromotionTemplate::PHASE_OUTER,
            $appliedIds,
        );

        $outerApplied = null;
        if ($outerPromotion !== null) {
            $this->promotionService->apply($outerPromotion, $context);

            $outerApplied = [
                'promotionId' => $outerPromotion->getId(),
                'promotionName' => $outerPromotion->getName(),
                'templateName' => $outerPromotion->getTemplate()?->getName(),
                'type' => $outerPromotion->getTemplate()?->getType(),
                'config' => $outerPromotion->getConfig(),
                'phase' => 'outer',
            ];
            $appliedIds[] = (int) $outerPromotion->getId();
        }

        $bestPricePromotion = $this->applyBestPricePromotion($context, $appliedIds);

        // Write to meta channel — Trade never sees this structure
        $result = ['inner' => $innerApplied];
        if ($outerApplied !== null) {
            $result['outer'] = $outerApplied;
        }
        if ($bestPricePromotion !== null) {
            $result['bestPrice'] = $bestPricePromotion;
        }
        $context->meta['promotion'] = $result;
    }

    /**
     * @param list<int> $excludedIds
     */
    private function getFirstStandardAvailable(PriceCalculationContext $context, int $phase, array $excludedIds): ?Promotion
    {
        $skippedIds = [];
        while (true) {
            $promotion = $this->promotionService->getFirstAvailable($context, $phase, [...$excludedIds, ...$skippedIds]);
            if ($promotion === null) {
                return null;
            }
            if ($promotion->getConflictMode() !== Promotion::CONFLICT_BEST_PRICE) {
                return $promotion;
            }
            $skippedIds[] = (int) $promotion->getId();
        }
    }

    /**
     * @param list<int> $excludedIds
     * @return array<string, mixed>|null
     */
    private function applyBestPricePromotion(PriceCalculationContext $context, array $excludedIds): ?array
    {
        $candidates = array_merge(
            $this->promotionService->getAvailable($context, PromotionTemplate::PHASE_INNER, $excludedIds),
            $this->promotionService->getAvailable($context, PromotionTemplate::PHASE_OUTER, $excludedIds),
        );

        $winner = null;
        $lowestTotal = $context->totalAmount;
        $evaluations = [];
        foreach ($candidates as $candidate) {
            if ($candidate->getConflictMode() !== Promotion::CONFLICT_BEST_PRICE) {
                continue;
            }

            $simulation = clone $context;
            $this->promotionService->apply($candidate, $simulation);
            $evaluations[] = [
                'promotionId' => $candidate->getId(),
                'totalAmount' => $simulation->totalAmount,
            ];
            if ($winner === null || $simulation->totalAmount < $lowestTotal) {
                $winner = $candidate;
                $lowestTotal = $simulation->totalAmount;
            }
        }

        if ($winner === null) {
            return null;
        }

        $this->promotionService->apply($winner, $context);

        return [
            'promotionId' => $winner->getId(),
            'promotionName' => $winner->getName(),
            'templateName' => $winner->getTemplate()?->getName(),
            'type' => $winner->getTemplate()?->getType(),
            'totalAmount' => $context->totalAmount,
            'candidates' => $evaluations,
        ];
    }
}
