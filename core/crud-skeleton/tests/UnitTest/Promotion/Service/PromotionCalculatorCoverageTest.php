<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service;

use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionCalculator;
use App\Promotion\Service\PromotionServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Covers the remaining uncovered lines of PromotionCalculator.php:
 *   - line 64  inner-loop break for CONFLICT_EXCLUSIVE promotions
 *   - line 143 best-price candidate scan skipping non best-price entries
 */
#[AllowMockObjectsWithoutExpectations]
final class PromotionCalculatorCoverageTest extends TestCase
{
    private function createMockPromotion(int $id, string $name, string $type, string $conflictMode = Promotion::CONFLICT_STACKABLE): Promotion
    {
        $template = $this->createMock(PromotionTemplate::class);
        $template->method('getName')->willReturn($name . ' Template');
        $template->method('getType')->willReturn($type);

        $promotion = $this->createMock(Promotion::class);
        $promotion->method('getId')->willReturn($id);
        $promotion->method('getName')->willReturn($name);
        $promotion->method('getTemplate')->willReturn($template);
        $promotion->method('getConfig')->willReturn([]);
        $promotion->method('getConflictMode')->willReturn($conflictMode);

        return $promotion;
    }

    public function testExclusiveConflictModeStopsInnerLoop(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $exclusive = $this->createMockPromotion(1, 'Exclusive', 'discount', Promotion::CONFLICT_EXCLUSIVE);
        $second = $this->createMockPromotion(3, 'Would-Be Second', 'discount', Promotion::CONFLICT_STACKABLE);
        $outer = $this->createMockPromotion(2, 'Outer', 'discount');

        $innerCallCount = 0;
        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($exclusive, $second, $outer, &$innerCallCount) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    $innerCallCount++;
                    if ($innerCallCount === 1) {
                        return $exclusive;
                    }
                    return $second;
                }
                return $outer;
            });

        $applied = [];
        $service->method('apply')
            ->willReturnCallback(function (Promotion $promotion) use (&$applied) {
                $applied[] = $promotion->getId();
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        // The exclusive promo is applied once in the INNER phase; the loop then
        // breaks before the second inner candidate is ever fetched or applied.
        // Without the exclusive break the inner loop would apply id 3 as well.
        self::assertSame([1, 2], $applied);
        self::assertCount(1, $context->meta['promotion']['inner']);
        self::assertSame('discount', $context->meta['promotion']['inner'][0]['type']);
    }

    public function testBestPricePromotionSkippedDuringStandardScan(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $bestPrice = $this->createMockPromotion(20, 'Best Price', 'full_reduction', Promotion::CONFLICT_BEST_PRICE);
        $standard = $this->createMockPromotion(21, 'Standard', 'full_reduction');

        $service = $this->createMock(PromotionServiceInterface::class);
        $innerCallCount = 0;
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($bestPrice, $standard, &$innerCallCount) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    $innerCallCount++;
                    // First scan returns the best-price promo, which the
                    // calculator must skip and retry with its id excluded.
                    if ($innerCallCount === 1) {
                        return $bestPrice;
                    }
                    return $innerCallCount === 2 ? $standard : null;
                }
                return null;
            });

        $applied = [];
        $service->method('apply')
            ->willReturnCallback(function (Promotion $promotion) use (&$applied) {
                $applied[] = $promotion->getId();
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        // Only the standard promotion is applied; best_price is never applied
        // as a standard (inner/outer) promotion.
        self::assertSame([21], $applied);
        self::assertSame(21, $context->meta['promotion']['inner'][0]['promotionId']);
    }

    public function testBestPriceCandidateScanSkipsNonBestPricePromotions(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $stackable = $this->createMockPromotion(10, 'Stackable', 'full_reduction', Promotion::CONFLICT_STACKABLE);
        $bestPrice = $this->createMockPromotion(11, 'Best Price', 'full_reduction', Promotion::CONFLICT_BEST_PRICE);

        $service = $this->createMock(PromotionServiceInterface::class);
        // No standard (non best-price) promotion is selected first.
        $service->method('getFirstAvailable')->willReturn(null);

        // Only the INNER phase exposes the mixed candidate pool; the OUTER pool
        // is empty so the best-price promotion appears exactly once.
        $service->method('getAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($stackable, $bestPrice) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    return [$stackable, $bestPrice];
                }
                return [];
            });

        $service->method('apply')
            ->willReturnCallback(function (Promotion $promotion, PriceCalculationContext $ctx) {
                $ctx->totalAmount -= 10000;
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        $bestPriceMeta = $context->meta['promotion']['bestPrice'];
        self::assertSame(11, $bestPriceMeta['promotionId']);
        self::assertSame('Best Price', $bestPriceMeta['promotionName']);
        self::assertSame('full_reduction', $bestPriceMeta['type']);
        // The simulation runs against a clone; only the final apply hits the
        // real context: 50000 - 10000 = 40000.
        self::assertSame(40000, $bestPriceMeta['totalAmount']);
        // Only best-price promotions are simulated/recorded; the stackable
        // candidate is skipped on line 143 and never appears in the report.
        self::assertSame([11], array_column($bestPriceMeta['candidates'], 'promotionId'));
        self::assertSame(40000, $context->totalAmount);
    }
}
