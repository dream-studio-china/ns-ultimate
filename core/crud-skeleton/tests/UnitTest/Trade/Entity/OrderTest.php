<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Entity;

use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Store\Entity\Specification;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function testConstructorInitializesCoreFields(): void
    {
        $order = new Order();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $order->getUuid()
        );
        self::assertNull($order->getUser());
        self::assertSame(0, $order->getTotalAmount());
        self::assertSame(0.0, $order->getTotalAmountAsFloat());
        self::assertSame('CNY', $order->getCurrency());
        self::assertSame('draft', $order->getStatus());
        self::assertNull($order->getNotes());
        self::assertNull($order->getMetadata());
        self::assertNull($order->getCancelledAt());
        self::assertNull($order->getCompletedAt());
        self::assertNull($order->getPaidAt());
        self::assertNull($order->getRefundedAt());
        self::assertNull($order->getFulfilledAt());
        self::assertNull($order->getPaymentMethod());
        self::assertNull($order->getTrackingNumber());
        self::assertNull($order->getShippingAddress());
        self::assertNull($order->getRefundReason());
        self::assertCount(0, $order->getItems());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getCreatedAt());
        self::assertNull($order->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testToString(): void
    {
        $order = new Order();
        $order->setTotalAmount(1500);
        self::assertStringContainsString('15.00', (string) $order);
    }

    public function testSetStatus(): void
    {
        $order = new Order();
        self::assertSame('draft', $order->getStatus());

        $order->setStatus('pending');
        self::assertSame('pending', $order->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testStatusConstants(): void
    {
        self::assertSame('draft', Order::STATUS_DRAFT);
        self::assertSame('pending', Order::STATUS_PENDING);
        self::assertSame('confirmed', Order::STATUS_CONFIRMED);
        self::assertSame('paid', Order::STATUS_PAID);
        self::assertSame('fulfilled', Order::STATUS_FULFILLED);
        self::assertSame('completed', Order::STATUS_COMPLETED);
        self::assertSame('cancelled', Order::STATUS_CANCELLED);
        self::assertSame('refunded', Order::STATUS_REFUNDED);
    }

    public function testCurrencyIsUpperCase(): void
    {
        $order = new Order();
        $order->setCurrency('cny');
        self::assertSame('CNY', $order->getCurrency());
    }

    public function testTotalAmount(): void
    {
        $order = new Order();
        $order->setTotalAmount(2000);
        self::assertSame(2000, $order->getTotalAmount());
        self::assertSame(20.0, $order->getTotalAmountAsFloat());
    }

    #[Group('low-value')]
    public function testNotes(): void
    {
        $order = new Order();
        $order->setNotes('Test notes');
        self::assertSame('Test notes', $order->getNotes());

        $order->setNotes(null);
        self::assertNull($order->getNotes());
    }

    #[Group('low-value')]
    public function testMetadata(): void
    {
        $order = new Order();
        $meta = ['ref' => 'abc'];
        $order->setMetadata($meta);
        self::assertSame($meta, $order->getMetadata());

        $order->setMetadata(null);
        self::assertNull($order->getMetadata());
    }

    #[Group('low-value')]
    public function testTimestamps(): void
    {
        $order = new Order();
        $before = new \DateTimeImmutable();

        $order->setCancelledAt(new \DateTimeImmutable('+1 hour'));
        self::assertNotNull($order->getCancelledAt());
        self::assertGreaterThan($before, $order->getCancelledAt());

        $order->setCompletedAt(new \DateTimeImmutable('+2 hours'));
        self::assertNotNull($order->getCompletedAt());
        self::assertGreaterThan($before, $order->getCompletedAt());
    }

    public function testOrderItemRelationships(): void
    {
        $order = new Order();
        $item1 = new OrderItem();
        $item2 = new OrderItem();

        $order->addItem($item1);
        $order->addItem($item2);

        self::assertCount(2, $order->getItems());
        self::assertTrue($order->getItems()->contains($item1));
        self::assertTrue($order->getItems()->contains($item2));
        self::assertSame($order, $item1->getOrder());
        self::assertSame($order, $item2->getOrder());

        $order->removeItem($item1);
        self::assertCount(1, $order->getItems());
        self::assertFalse($order->getItems()->contains($item1));
        self::assertNull($item1->getOrder());
    }

    public function testAddOrderItemDoesNotDuplicate(): void
    {
        $order = new Order();
        $item = new OrderItem();

        $order->addItem($item);
        $order->addItem($item);
        self::assertCount(1, $order->getItems());
    }

    public function testPrePersist(): void
    {
        $reflection = new \ReflectionClass(Order::class);
        $order = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('uuid');
        $prop->setValue($order, '00000000-0000-0000-0000-000000000000');

        $order->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getCreatedAt());
    }

    #[Group('low-value')]
    public function testTouch(): void
    {
        $order = new Order();
        self::assertNull($order->getUpdatedAt());
        $order->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testNewTimestamps(): void
    {
        $order = new Order();
        $before = new \DateTimeImmutable();

        $order->setPaidAt(new \DateTimeImmutable('+1 hour'));
        self::assertNotNull($order->getPaidAt());
        self::assertGreaterThan($before, $order->getPaidAt());

        $order->setRefundedAt(new \DateTimeImmutable('+2 hours'));
        self::assertNotNull($order->getRefundedAt());
        self::assertGreaterThan($before, $order->getRefundedAt());

        $order->setFulfilledAt(new \DateTimeImmutable('+3 hours'));
        self::assertNotNull($order->getFulfilledAt());
        self::assertGreaterThan($before, $order->getFulfilledAt());

        $order->setPaidAt(null);
        self::assertNull($order->getPaidAt());

        $order->setRefundedAt(null);
        self::assertNull($order->getRefundedAt());

        $order->setFulfilledAt(null);
        self::assertNull($order->getFulfilledAt());
    }

    #[Group('low-value')]
    public function testPaymentMethod(): void
    {
        $order = new Order();
        $order->setPaymentMethod('wallet');
        self::assertSame('wallet', $order->getPaymentMethod());

        $order->setPaymentMethod(null);
        self::assertNull($order->getPaymentMethod());
    }

    #[Group('low-value')]
    public function testTrackingNumber(): void
    {
        $order = new Order();
        $order->setTrackingNumber('SF1234567890');
        self::assertSame('SF1234567890', $order->getTrackingNumber());

        $order->setTrackingNumber(null);
        self::assertNull($order->getTrackingNumber());
    }

    #[Group('low-value')]
    public function testShippingAddress(): void
    {
        $order = new Order();
        $order->setShippingAddress('123 Main St, Beijing');
        self::assertSame('123 Main St, Beijing', $order->getShippingAddress());

        $order->setShippingAddress(null);
        self::assertNull($order->getShippingAddress());
    }

    #[Group('low-value')]
    public function testRefundReason(): void
    {
        $order = new Order();
        $order->setRefundReason('Customer changed mind');
        self::assertSame('Customer changed mind', $order->getRefundReason());

        $order->setRefundReason(null);
        self::assertNull($order->getRefundReason());
    }
}
