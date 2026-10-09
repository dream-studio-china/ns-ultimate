<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Entity;

use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SpecificationTest extends TestCase
{
    public function testConstructorInitializesCoreFields(): void
    {
        $spec = new Specification();

        self::assertSame('', $spec->getName());
        self::assertSame(0, $spec->getPrice());
        self::assertSame(0.0, $spec->getPriceAsFloat());
        self::assertSame('active', $spec->getStatus());
        self::assertTrue($spec->isActive());
        self::assertSame(0, $spec->getSort());
        self::assertFalse($spec->getIsDeleted());
        self::assertNull($spec->getProduct());
        self::assertInstanceOf(\DateTimeImmutable::class, $spec->getCreatedAt());
        self::assertNull($spec->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testToString(): void
    {
        $product = new Product();
        $product->setName('Phone');

        $spec = new Specification();
        $spec->setProduct($product);
        $spec->setName('128GB');

        self::assertStringContainsString('Phone', (string) $spec);
        self::assertStringContainsString('128GB', (string) $spec);
    }

    #[Group('low-value')]
    public function testToStringWithNullProduct(): void
    {
        $spec = new Specification();
        $spec->setName('Standalone');
        self::assertStringContainsString('Standalone', (string) $spec);
    }

    public function testPriceValidation(): void
    {
        $spec = new Specification();

        $spec->setPrice(1000);
        self::assertSame(1000, $spec->getPrice());
        self::assertSame(10.0, $spec->getPriceAsFloat());

        $spec->setPrice(0);
        self::assertSame(0, $spec->getPrice());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Price cannot be negative.');
        $spec->setPrice(-1);
    }

    #[Group('low-value')]
    public function testSettersAreFluent(): void
    {
        $spec = new Specification();
        $result = $spec->setName('Test')->setPrice(500)->setSort(1);

        self::assertSame($spec, $result);
        self::assertSame('Test', $spec->getName());
        self::assertSame(500, $spec->getPrice());
        self::assertSame(1, $spec->getSort());
    }

    public function testStatusValidation(): void
    {
        $spec = new Specification();

        $spec->setStatus(Specification::STATUS_ACTIVE);
        self::assertSame('active', $spec->getStatus());
        self::assertTrue($spec->isActive());

        $spec->setStatus(Specification::STATUS_INACTIVE);
        self::assertSame('inactive', $spec->getStatus());
        self::assertFalse($spec->isActive());

        $this->expectException(\InvalidArgumentException::class);
        $spec->setStatus('invalid_status');
    }

    #[Group('low-value')]
    public function testSort(): void
    {
        $spec = new Specification();
        self::assertSame(0, $spec->getSort());

        $spec->setSort(10);
        self::assertSame(10, $spec->getSort());
    }

    #[Group('low-value')]
    public function testIsDeleted(): void
    {
        $spec = new Specification();
        self::assertFalse($spec->getIsDeleted());

        $spec->setIsDeleted(true);
        self::assertTrue($spec->getIsDeleted());
    }

    #[Group('low-value')]
    public function testProductAssociation(): void
    {
        $product = new Product();
        $spec = new Specification();

        $spec->setProduct($product);
        self::assertSame($product, $spec->getProduct());

        $spec->setProduct(null);
        self::assertNull($spec->getProduct());
    }

    #[Group('low-value')]
    public function testTouch(): void
    {
        $spec = new Specification();
        self::assertNull($spec->getUpdatedAt());

        $spec->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $spec->getUpdatedAt());
    }

    public function testPrePersistInitializesCreatedAtWhenNotSet(): void
    {
        $reflection = new \ReflectionClass(Specification::class);
        $spec = $reflection->newInstanceWithoutConstructor();
        $spec->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $spec->getCreatedAt());
    }
}
