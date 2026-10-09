<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Entity;

use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OrderItemTest extends TestCase
{
    public function testConstructorInitializesCoreFields(): void
    {
        $item = new OrderItem();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $item->getUuid()
        );
        self::assertNull($item->getOrder());
        self::assertNull($item->getSpecificationUuid());
        self::assertNull($item->getSpecificationTitle());
        self::assertSame(1, $item->getQuantity());
        self::assertSame(0, $item->getUnitPrice());
        self::assertSame(0, $item->getPrice());
        self::assertSame(0, $item->getCost());
        self::assertSame(0, $item->getProfit());
        self::assertNull($item->getSpecSnapshot());
        self::assertNull($item->getProductSnapshot());
        self::assertNull($item->getMetadata());
        self::assertInstanceOf(\DateTimeImmutable::class, $item->getCreatedAt());
    }

    #[Group('low-value')]
    public function testToString(): void
    {
        $item = new OrderItem();
        $item->setSpecificationTitle('Red');
        $item->setQuantity(3);
        $item->setUnitPrice(500);

        self::assertStringContainsString('Red', (string) $item);
        self::assertStringContainsString('x3', (string) $item);
    }

    public function testQuantityValidation(): void
    {
        $item = new OrderItem();
        $item->setQuantity(5);
        self::assertSame(5, $item->getQuantity());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be at least 1.');
        $item->setQuantity(0);
    }

    #[Group('low-value')]
    public function testQuantityCannotBeNegative(): void
    {
        $item = new OrderItem();
        $this->expectException(\InvalidArgumentException::class);
        $item->setQuantity(-1);
    }

    public function testPrePersistPopulatesSnapshotsAndCalculatesPrice(): void
    {
        $item = new OrderItem();
        $item->setSpecificationUuid('11111111-1111-1111-1111-111111111111');
        $item->setSpecSnapshot([
            'id' => null,
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'name' => 'TestSpec',
            'productId' => null,
        ]);
        $item->setProductSnapshot([
            'id' => null,
            'name' => 'TestProduct',
        ]);
        $item->setQuantity(2);
        $item->setUnitPrice(1500);

        $item->prePersist();

        self::assertSame('TestSpec', $item->getSpecificationTitle());
        self::assertSame(3000, $item->getPrice());
        self::assertSame('11111111-1111-1111-1111-111111111111', $item->getSpecificationUuid());
    }

    public function testPrePersistWithNullSpecificationDoesNotPopulate(): void
    {
        $item = new OrderItem();
        $item->setUnitPrice(100);
        $item->setQuantity(3);

        $item->prePersist();

        self::assertNull($item->getSpecificationTitle());
        self::assertNull($item->getSpecSnapshot());
        self::assertNull($item->getProductSnapshot());
        self::assertSame(300, $item->getPrice());
    }

    public function testPrePersistPriceCalculation(): void
    {
        $item = new OrderItem();
        $item->setUnitPrice(299);
        $item->setQuantity(5);

        $item->prePersist();

        self::assertSame(1495, $item->getPrice());
    }

    public function testCostAndProfit(): void
    {
        $item = new OrderItem();
        $item->setPrice(1000);

        self::assertSame(0, $item->getCost());
        self::assertSame(0, $item->getProfit());

        $item->setCost(600);
        self::assertSame(600, $item->getCost());
        self::assertSame(400, $item->getProfit());
    }

    #[Group('low-value')]
    public function testSetProfitDirectly(): void
    {
        $item = new OrderItem();
        $item->setProfit(500);
        self::assertSame(500, $item->getProfit());
    }

    #[Group('low-value')]
    public function testMetadata(): void
    {
        $item = new OrderItem();
        $item->setMetadata(['color' => 'red']);
        self::assertSame(['color' => 'red'], $item->getMetadata());

        $item->setMetadata(null);
        self::assertNull($item->getMetadata());
    }

    #[Group('low-value')]
    public function testSettersAreFluent(): void
    {
        $item = new OrderItem();
        $result = $item->setQuantity(2)->setUnitPrice(100)->setPrice(200);

        self::assertSame($item, $result);
        self::assertSame(2, $item->getQuantity());
        self::assertSame(100, $item->getUnitPrice());
        self::assertSame(200, $item->getPrice());
    }

    public function testPrePersistInitializesCreatedAt(): void
    {
        $reflection = new \ReflectionClass(OrderItem::class);
        $item = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('uuid');
        $prop->setValue($item, '00000000-0000-0000-0000-000000000000');

        $item->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $item->getCreatedAt());
    }
}
