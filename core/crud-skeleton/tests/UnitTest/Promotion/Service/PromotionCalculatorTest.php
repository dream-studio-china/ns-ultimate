<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service;

use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionCalculator;
use App\Promotion\Service\PromotionServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use PHPUnit\Framework\TestCase;

final class PromotionCalculatorTest extends TestCase
{
    private function createMockPromotion(int $id, string $name, string $templateName, string $type, array $config, string|int $conflictMode = Promotion::CONFLICT_STACKABLE): Promotion
    {
        $template = $this->createMock(PromotionTemplate::class);
        $template->method('getName')->willReturn($templateName);
        $template->method('getType')->willReturn($type);

        $promotion = $this->createMock(Promotion::class);
        $promotion->method('getId')->willReturn($id);
        $promotion->method('getName')->willReturn($name);
        $promotion->method('getTemplate')->willReturn($template);
        $promotion->method('getConfig')->willReturn($config);
        $promotion->method('getConflictMode')->willReturn($conflictMode);

        return $promotion;
    }

    public function testCalculateAppliesInnerPhasePromotions(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $promotion1 = $this->createMockPromotion(1, 'Summer Sale', 'Full Reduction', 'full_reduction', ['threshold' => 200.00]);
        $promotion2 = $this->createMockPromotion(2, 'Fall Sale', 'Discount', 'discount', ['rate' => 80]);

        $callIndex = 0;
        $innerReturns = [$promotion1, $promotion2, null];

        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use (&$callIndex, $innerReturns) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    $idx = $callIndex++;
                    return $innerReturns[$idx] ?? null;
                }
                return null;
            });

        $appliedPromotions = [];
        $service->method('apply')
            ->willReturnCallback(function (Promotion $promotion, PriceCalculationContext $ctx) use (&$appliedPromotions) {
                $appliedPromotions[] = $promotion;
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        self::assertCount(2, $appliedPromotions);
        self::assertSame($promotion1, $appliedPromotions[0]);
        self::assertSame($promotion2, $appliedPromotions[1]);

        $inner = $context->meta['promotion']['inner'];
        self::assertCount(2, $inner);
        self::assertSame(1, $inner[0]['promotionId']);
        self::assertSame('Summer Sale', $inner[0]['promotionName']);
        self::assertSame('full_reduction', $inner[0]['type']);
        self::assertSame(0, $inner[0]['iteration']);
        self::assertSame(2, $inner[1]['promotionId']);
        self::assertSame(1, $inner[1]['iteration']);
        self::assertArrayHasKey('snapshot', $inner[0]);
    }

    public function testCalculateAppliesOuterPhasePromotion(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $outerPromotion = $this->createMockPromotion(3, 'Global Sale', 'Free Shipping', 'free_shipping', []);

        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($outerPromotion) {
                if ($phase === PromotionTemplate::PHASE_INNER) return null;
                return $outerPromotion;
            });

        $applied = false;
        $service->method('apply')
            ->willReturnCallback(function (Promotion $promotion, PriceCalculationContext $ctx) use ($outerPromotion, &$applied) {
                $applied = ($promotion === $outerPromotion);
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        self::assertTrue($applied);

        $outer = $context->meta['promotion']['outer'];
        self::assertSame(3, $outer['promotionId']);
        self::assertSame('free_shipping', $outer['type']);
        self::assertSame('outer', $outer['phase']);
    }

    public function testCalculateNoPromotionsAvailable(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')->willReturn(null);

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        self::assertSame([], $context->meta['promotion']['inner']);
        self::assertArrayNotHasKey('outer', $context->meta['promotion']);
    }

    public function testCalculateStackableConflictModeLoops(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $promotion1 = $this->createMockPromotion(1, 'First', 'Template', 'full_reduction', [], Promotion::CONFLICT_STACKABLE);
        $promotion2 = $this->createMockPromotion(2, 'Second', 'Template', 'full_reduction', []);

        $innerCallCount = 0;
        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($promotion1, $promotion2, &$innerCallCount) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    $innerCallCount++;
                    if ($innerCallCount === 1) return $promotion1;
                    if ($innerCallCount === 2) return $promotion2;
                    return null;
                }
                return null;
            });

        $applyCount = 0;
        $service->method('apply')
            ->willReturnCallback(function () use (&$applyCount) {
                $applyCount++;
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        self::assertSame(2, $applyCount);
        self::assertCount(2, $context->meta['promotion']['inner']);
    }

    public function testCalculateLockItemConflictModeTracksIds(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $promotion1 = $this->createMockPromotion(10, 'LockItem', 'Template', 'discount', [], Promotion::CONFLICT_LOCK_ITEM);
        $promotion2 = $this->createMockPromotion(11, 'Next', 'Template', 'full_reduction', []);

        $innerCallCount = 0;
        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($promotion1, $promotion2, &$innerCallCount) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    $innerCallCount++;
                    if ($innerCallCount === 1) return $promotion1;
                    if ($innerCallCount === 2) return $promotion2;
                    return null;
                }
                return null;
            });

        $applyCount = 0;
        $service->method('apply')
            ->willReturnCallback(function () use (&$applyCount) {
                $applyCount++;
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        self::assertSame(1, $applyCount);
        self::assertCount(1, $context->meta['promotion']['inner']);
    }

    public function testCalculateAppliedPromotionsSnapshot(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 123456;
        $context->items = [['unitPrice' => 10.00, 'quantity' => 1, 'specificationId' => 1]];

        $promotion = $this->createMockPromotion(1, 'Test', 'Full Reduction', 'full_reduction', ['threshold' => 100.00]);

        $innerCallCount = 0;
        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($promotion, &$innerCallCount) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    $innerCallCount++;
                    if ($innerCallCount === 1) return $promotion;
                    return null;
                }
                return null;
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        $inner = $context->meta['promotion']['inner'];
        self::assertCount(1, $inner);
        self::assertSame(123456, $inner[0]['snapshot']['totalAmount']);
        self::assertSame(1, $inner[0]['snapshot']['itemsCount']);
    }

    public function testCalculateConfigInAppliedPromotions(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;

        $config = ['threshold' => 200.00, 'amount' => 20.00];
        $promotion = $this->createMockPromotion(1, 'ConfigTest', 'Template', 'full_reduction', $config);

        $innerCallCount = 0;
        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($promotion, &$innerCallCount) {
                if ($phase === PromotionTemplate::PHASE_INNER) {
                    $innerCallCount++;
                    if ($innerCallCount === 1) return $promotion;
                    return null;
                }
                return null;
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        $inner = $context->meta['promotion']['inner'];
        self::assertCount(1, $inner);
        self::assertSame($config, $inner[0]['config']);
    }

    public function testGetPriority(): void
    {
        self::assertSame(60, PromotionCalculator::getPriority());
    }

    public function testMetaNotOverwrittenWhenAlreadySet(): void
    {
        $context = new PriceCalculationContext([]);
        $context->totalAmount = 50000;
        $context->meta['existing'] = 'should-survive';

        $promotion = $this->createMockPromotion(1, 'Test', 'Template', 'full_reduction', []);

        $service = $this->createMock(PromotionServiceInterface::class);
        $service->method('getFirstAvailable')
            ->willReturnCallback(function (PriceCalculationContext $ctx, ?int $phase) use ($promotion) {
                if ($phase === PromotionTemplate::PHASE_INNER) return $promotion;
                return null;
            });

        $calculator = new PromotionCalculator($service);
        $calculator->calculate($context);

        self::assertSame('should-survive', $context->meta['existing']);
        self::assertIsArray($context->meta['promotion']);
    }
}
