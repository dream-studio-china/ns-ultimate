<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Pricing;

use App\Trade\Exception\SpecificationNotFoundException;
use App\Trade\Service\Catalog\CatalogItem;
use App\Trade\Service\Catalog\CatalogResolverInterface;
use App\Trade\Service\Pricing\BasePriceCalculator;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use App\Trade\Service\Pricing\QuantityCalculator;
use App\Trade\Service\Pricing\TotalAggregator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    #[Group('low-value')]
    public function testContextInitializesCorrectly(): void
    {
        $input = [['specificationId' => 1, 'quantity' => 2]];
        $context = new PriceCalculationContext($input, 'CNY');

        self::assertSame($input, $context->inputItems);
        self::assertSame('CNY', $context->currency);
        self::assertSame(0, $context->totalAmount);
        self::assertSame([], $context->items);
        self::assertSame([], $context->meta);
    }

    #[Group('low-value')]
    public function testContextDefaultCurrency(): void
    {
        $context = new PriceCalculationContext([]);
        self::assertSame('CNY', $context->currency);
    }

    #[Group('low-value')]
    public function testResultFromContext(): void
    {
        $context = new PriceCalculationContext([], 'EUR');
        $context->totalAmount = 5000;
        $context->items = [['specificationId' => 1, 'price' => 5000]];

        $result = PriceCalculationResult::fromContext($context);

        self::assertSame(5000, $result->totalAmount);
        self::assertSame('EUR', $result->currency);
        self::assertSame($context->items, $result->items);
    }

    #[Group('low-value')]
    public function testResultConstructor(): void
    {
        $items = [['price' => 100]];
        $result = new PriceCalculationResult(100, 'CNY', $items);

        self::assertSame(100, $result->totalAmount);
        self::assertSame('CNY', $result->currency);
        self::assertSame($items, $result->items);
    }

    private function catalogItem(int $id, string $uuid, string $name, int $price, string $productName = 'Product'): CatalogItem
    {
        return new CatalogItem(
            id: $id,
            uuid: $uuid,
            name: $name,
            price: $price,
            status: 'active',
            isDeleted: false,
            productId: 10,
            productUuid: '00000000-0000-0000-0000-000000000001',
            productName: $productName,
            productIsDeleted: false,
            productStatus: 'active',
            storeUuid: null,
            storeId: null,
        );
    }

    public function testBasePriceCalculatorPopulatesUnitPriceAndSnapshots(): void
    {
        $item1 = $this->catalogItem(1, '00000000-0000-0000-0000-000000000011', '128GB', 100000, 'iPhone');
        $item2 = $this->catalogItem(2, '00000000-0000-0000-0000-000000000012', '128GB', 100000, 'iPhone');

        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturnOnConsecutiveCalls($item1, $item2);

        $calculator = new BasePriceCalculator($resolver);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 2],
            ['specificationId' => 2, 'quantity' => 1],
        ]);

        $calculator->calculate($context);

        self::assertCount(2, $context->items);

        self::assertSame(100000, $context->items[0]['unitPrice']);
        self::assertSame(2, $context->items[0]['quantity']);
        self::assertSame('128GB', $context->items[0]['specificationName']);
        self::assertSame('iPhone', $context->items[0]['productSnapshot']['name']);

        self::assertSame(100000, $context->items[1]['unitPrice']);
        self::assertSame(1, $context->items[1]['quantity']);
    }

    public function testBasePriceCalculatorThrowsWhenSpecNotFound(): void
    {
        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn(null);

        $calculator = new BasePriceCalculator($resolver);
        $context = new PriceCalculationContext([
            ['specificationId' => 999, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorThrowsWhenSpecDeleted(): void
    {
        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn(null);

        $calculator = new BasePriceCalculator($resolver);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorThrowsWhenSpecInactive(): void
    {
        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn(null);

        $calculator = new BasePriceCalculator($resolver);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorThrowsWhenProductNotAvailable(): void
    {
        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn(null);

        $calculator = new BasePriceCalculator($resolver);
        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 1],
        ]);

        $this->expectException(SpecificationNotFoundException::class);
        $calculator->calculate($context);
    }

    public function testBasePriceCalculatorDefaultQuantityIsOne(): void
    {
        $item = $this->catalogItem(1, '00000000-0000-0000-0000-000000000021', 'Test', 1000);

        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn($item);

        $calculator = new BasePriceCalculator($resolver);
        $context = new PriceCalculationContext([
            ['specificationId' => 1],
        ]);

        $calculator->calculate($context);

        self::assertSame(1, $context->items[0]['quantity']);
    }

    public function testQuantityCalculatorComputesPriceCorrectly(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['unitPrice' => 100, 'quantity' => 3, 'price' => 0],
            ['unitPrice' => 50, 'quantity' => 2, 'price' => 0],
            ['unitPrice' => 200, 'quantity' => 1, 'price' => 0],
        ];

        $calculator = new QuantityCalculator();
        $calculator->calculate($context);

        self::assertSame(300, $context->items[0]['price']);
        self::assertSame(100, $context->items[1]['price']);
        self::assertSame(200, $context->items[2]['price']);
    }

    public function testTotalAggregatorComputesTotalAmount(): void
    {
        $context = new PriceCalculationContext([]);
        $context->items = [
            ['price' => 300],
            ['price' => 100],
            ['price' => 200],
        ];

        $calculator = new TotalAggregator();
        $calculator->calculate($context);

        self::assertSame(600, $context->totalAmount);
    }

    public function testTotalAggregatorWithEmptyItems(): void
    {
        $context = new PriceCalculationContext([]);
        $calculator = new TotalAggregator();
        $calculator->calculate($context);

        self::assertSame(0, $context->totalAmount);
    }

    public function testPipelineExecutionOrder(): void
    {
        $item = $this->catalogItem(1, '00000000-0000-0000-0000-000000000031', 'Test', 1000);

        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn($item);

        $calculators = [
            new BasePriceCalculator($resolver),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        usort($calculators, function (PriceCalculatorInterface $a, PriceCalculatorInterface $b) {
            return $a::getPriority() <=> $b::getPriority();
        });

        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 5],
        ]);

        foreach ($calculators as $calculator) {
            $calculator->calculate($context);
        }

        $result = PriceCalculationResult::fromContext($context);

        self::assertSame(5000, $result->totalAmount);
        self::assertCount(1, $result->items);
        self::assertSame(1000, $result->items[0]['unitPrice']);
        self::assertSame(5, $result->items[0]['quantity']);
        self::assertSame(5000, $result->items[0]['price']);
    }

    public function testPricingPriorityOrder(): void
    {
        self::assertLessThan(QuantityCalculator::getPriority(), BasePriceCalculator::getPriority());
        self::assertLessThan(TotalAggregator::getPriority(), QuantityCalculator::getPriority());
    }

    #[Group('low-value')]
    public function testContextMalleableState(): void
    {
        $context = new PriceCalculationContext([]);
        $context->meta['promotion_applied'] = true;
        $context->meta['discount'] = 500;

        self::assertTrue($context->meta['promotion_applied']);
        self::assertSame(500, $context->meta['discount']);
    }

    public function testPipelineHandlesMultipleItemsWithDifferentPrices(): void
    {
        $item1 = $this->catalogItem(1, '00000000-0000-0000-0000-000000000041', 'Red', 1000, 'Phone');
        $item2 = $this->catalogItem(2, '00000000-0000-0000-0000-000000000042', 'Blue', 1100, 'Phone');

        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturnOnConsecutiveCalls($item1, $item2);

        $calculators = [
            new BasePriceCalculator($resolver),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        usort($calculators, function (PriceCalculatorInterface $a, PriceCalculatorInterface $b) {
            return $a::getPriority() <=> $b::getPriority();
        });

        $context = new PriceCalculationContext([
            ['specificationId' => 1, 'quantity' => 2],
            ['specificationId' => 2, 'quantity' => 3],
        ]);

        foreach ($calculators as $calculator) {
            $calculator->calculate($context);
        }

        $result = PriceCalculationResult::fromContext($context);
        self::assertSame(5300, $result->totalAmount);
    }
}
