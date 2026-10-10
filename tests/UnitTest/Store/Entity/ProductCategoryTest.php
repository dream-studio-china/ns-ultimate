<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use PHPUnit\Framework\TestCase;

final class ProductCategoryTest extends TestCase
{
    public function testDefaultsToGlobalEnabledCategory(): void
    {
        $category = new ProductCategory('Shoes', 'shoes');

        self::assertNull($category->getStore());
        self::assertSame('global', $category->getScopeKey());
        self::assertTrue($category->isEnabled());
        self::assertSame('Shoes', (string) $category);
    }

    public function testStoreScopeIsEncodedAndParentMustShareIt(): void
    {
        $store = new Store('north', 'North Store');
        $parent = (new ProductCategory('Shoes', 'shoes'))->setStore($store);
        $child = (new ProductCategory('Boots', 'boots'))->setStore($store)->setParent($parent);

        self::assertSame('store:'.$store->getUuid(), $child->getScopeKey());
        self::assertSame($parent, $child->getParent());
        self::assertCount(1, $parent->getChildren());
    }

    public function testRejectsParentFromDifferentStoreScope(): void
    {
        $parent = (new ProductCategory('Global', 'global'))->setStore(null);
        $store = new Store('north', 'North Store');
        $child = (new ProductCategory('Local', 'local'))->setStore($store);

        $this->expectException(\InvalidArgumentException::class);
        $child->setParent($parent);
    }

    public function testRejectsCategoryCycles(): void
    {
        $parent = new ProductCategory('Parent', 'parent');
        $child = new ProductCategory('Child', 'child');
        $child->setParent($parent);

        $this->expectException(\InvalidArgumentException::class);
        $parent->setParent($child);
    }
}
