<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Entity;

use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testConstructorInitializesCoreFields(): void
    {
        $product = new Product();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $product->getUuid()
        );
        self::assertSame('', $product->getName());
        self::assertNull($product->getDescription());
        self::assertSame('active', $product->getStatus());
        self::assertTrue($product->isActive());
        self::assertFalse($product->getIsDeleted());
        self::assertCount(0, $product->getSpecifications());
        self::assertInstanceOf(\DateTimeImmutable::class, $product->getCreatedAt());
        self::assertNull($product->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testToString(): void
    {
        $product = new Product();
        $product->setName('Test Product');
        self::assertStringContainsString('Test Product', (string) $product);
    }

    #[Group('low-value')]
    public function testSettersAreFluentAndTouchTimestamp(): void
    {
        $product = new Product();
        $result = $product->setName('New Name');

        self::assertSame($product, $result);
        self::assertSame('New Name', $product->getName());
        self::assertInstanceOf(\DateTimeImmutable::class, $product->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testDescriptionCanBeNull(): void
    {
        $product = new Product();
        $product->setDescription('A description');
        self::assertSame('A description', $product->getDescription());

        $product->setDescription(null);
        self::assertNull($product->getDescription());
        self::assertNotNull($product->getUpdatedAt());
    }

    public function testStatusValidation(): void
    {
        $product = new Product();

        $product->setStatus(Product::STATUS_ACTIVE);
        self::assertSame('active', $product->getStatus());
        self::assertTrue($product->isActive());

        $product->setStatus(Product::STATUS_INACTIVE);
        self::assertSame('inactive', $product->getStatus());
        self::assertFalse($product->isActive());

        $this->expectException(\InvalidArgumentException::class);
        $product->setStatus('invalid_status');
    }

    #[Group('low-value')]
    public function testIsDeleted(): void
    {
        $product = new Product();
        self::assertFalse($product->getIsDeleted());

        $product->setIsDeleted(true);
        self::assertTrue($product->getIsDeleted());

        $product->setIsDeleted(false);
        self::assertFalse($product->getIsDeleted());
    }

    #[Group('low-value')]
    public function testMetadata(): void
    {
        $product = new Product();
        self::assertNull($product->getMetadata());

        $metadata = ['key' => 'value', 'number' => 42];
        $product->setMetadata($metadata);
        self::assertSame($metadata, $product->getMetadata());

        $product->setMetadata(null);
        self::assertNull($product->getMetadata());
    }

    public function testSpecificationRelationships(): void
    {
        $product = new Product();
        $spec1 = new Specification();
        $spec1->setName('Red');
        $spec2 = new Specification();
        $spec2->setName('Blue');

        $product->addSpecification($spec1);
        $product->addSpecification($spec2);

        self::assertCount(2, $product->getSpecifications());
        self::assertTrue($product->getSpecifications()->contains($spec1));
        self::assertTrue($product->getSpecifications()->contains($spec2));
        self::assertSame($product, $spec1->getProduct());
        self::assertSame($product, $spec2->getProduct());

        $product->removeSpecification($spec1);
        self::assertCount(1, $product->getSpecifications());
        self::assertFalse($product->getSpecifications()->contains($spec1));
        self::assertTrue($product->getSpecifications()->contains($spec2));
        self::assertNull($spec1->getProduct());
    }

    public function testAddSpecificationDoesNotDuplicate(): void
    {
        $product = new Product();
        $spec = new Specification();
        $spec->setName('Test');

        $product->addSpecification($spec);
        $product->addSpecification($spec);
        self::assertCount(1, $product->getSpecifications());
    }

    public function testPrePersistInitializesCreatedAtWhenConstructorWasSkipped(): void
    {
        $reflection = new \ReflectionClass(Product::class);
        $product = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->hasProperty('uuid') ? $reflection->getProperty('uuid') : (new \ReflectionClass(\App\Store\Entity\Product::class))->getProperty('uuid');
        $property->setValue($product, '00000000-0000-0000-0000-000000000000');

        $product->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $product->getCreatedAt());
    }

    public function testPrePersistKeepsCreatedAtWhenAlreadySet(): void
    {
        $product = new Product();
        $createdAt = $product->getCreatedAt();
        $product->prePersist();
        self::assertSame($createdAt, $product->getCreatedAt());
    }
}
