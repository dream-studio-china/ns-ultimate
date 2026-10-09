<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Common\Entity;


use PHPUnit\Framework\Attributes\Group;
use App\Common\Entity\Category;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Covers the last uncovered branch of App\Common\Entity\Category:
 * getChildren() lazily re-initializing the collection when it is null (line 121).
 */
#[Group('low-value')]
final class CategoryEntityCoverageTest extends TestCase
{
    public function testGetChildrenReinitializesWhenPropertyIsNull(): void
    {
        $category = new Category('name', 'slug');
        $reflection = new \ReflectionProperty(Category::class, 'children');
        $reflection->setValue($category, null);

        self::assertInstanceOf(Collection::class, $category->getChildren());
        self::assertCount(0, $category->getChildren());
    }

    public function testAddChildReinitializesNullCollection(): void
    {
        $category = new Category('Parent', 'parent');
        $reflection = new \ReflectionProperty(Category::class, 'children');
        $reflection->setValue($category, null);

        $child = new Category('Child', 'child');
        $category->addChild($child);

        self::assertCount(1, $category->getChildren());
        self::assertTrue($category->getChildren()->contains($child));
        self::assertSame($category, $child->getParent());
    }

    public function testChildrenFromReflectionWithoutConstructorAreLazilyInitialized(): void
    {
        $reflection = new \ReflectionClass(Category::class);
        /** @var Category $category */
        $category = $reflection->newInstanceWithoutConstructor();

        self::assertCount(0, $category->getChildren());
    }
}
