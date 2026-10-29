<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Service\OrderItemFilter;
use NsUltimate\Business\Printer\Value\PrintCategoryContext;
use NsUltimate\Business\Printer\Value\PrintItemContext;
use NsUltimate\Business\Printer\Value\PrintProductContext;
use PHPUnit\Framework\TestCase;

final class OrderItemFilterTest extends TestCase
{
    public function testValidateAcceptsExpressionsAndRejectsBrokenOnes(): void
    {
        $filter = new OrderItemFilter();
        $filter->validate(null);
        $filter->validate('');
        $filter->validate('entity.getQuantity() > 1');
        $filter->validate('entity.getProduct().getCategory().getName() == "Kitchen"');
        self::assertTrue(true);

        foreach (['entity.getQuantity( >', str_repeat('a', 501), 'entity.'] as $invalid) {
            try {
                $filter->validate($invalid);
                self::fail('Invalid filter was accepted: '.$invalid);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Item filter', $exception->getMessage());
            }
        }
    }

    public function testMatchesItemProductAndCategory(): void
    {
        $filter = new OrderItemFilter();
        $product = new PrintProductContext('product-uuid', 'Burger', ['kitchenPrinterEnable' => true], new PrintCategoryContext('category-uuid', 'Kitchen', 'kitchen'));
        $item = new PrintItemContext(
            ['name' => 'Burger', 'specification' => 'Large', 'quantity' => 2, 'unitPrice' => 100, 'amount' => 200, 'productUuid' => 'product-uuid'],
            ['kitchenPrinterEnable' => true],
            $product,
        );

        self::assertTrue($filter->matches(null, $item));
        self::assertTrue($filter->matches('entity.getQuantity() == 2', $item));
        self::assertTrue($filter->matches('entity.getProductSettings()["kitchenPrinterEnable"] == true', $item));
        self::assertTrue($filter->matches('entity.getProduct().getCategory().getName() == "Kitchen"', $item));
        self::assertFalse($filter->matches('entity.getProduct().getCategory().getSlug() == "drinks"', $item));

        $withoutProduct = new PrintItemContext(['quantity' => 1, 'productSettings' => []], [], null);
        self::assertFalse($filter->matches('entity.getProduct().getCategory().getName() == "Kitchen"', $withoutProduct));
    }
}
