<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\StoreOutboxMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StoreOutboxMessageLifecycleTest extends TestCase
{
    public function testNewMessageHasNoIdAndOccurredAtDefaultsToAvailableAt(): void
    {
        $message = new StoreOutboxMessage('store.order.accepted.v1', 'store_order', 'order-uuid', ['orderUuid' => 'order-uuid']);

        self::assertNull($message->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $message->getOccurredAt());
        self::assertSame($message->getOccurredAt(), $message->getAvailableAt());
    }

    public function testConstructorAcceptsExplicitOccurredAt(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-08-01 10:30:00');

        $message = new StoreOutboxMessage('store.order.rejected.v1', 'store_order', 'order-uuid', [], $occurredAt);

        self::assertSame($occurredAt, $message->getOccurredAt());
        self::assertSame($occurredAt, $message->getAvailableAt());
    }

    #[Group('low-value')]
    public function testIdBecomesAvailableAfterPersistenceIdAssignment(): void
    {
        $message = new StoreOutboxMessage('inventory.reservation.requested.v1', 'inventory_reservation', 'reservation-uuid', []);
        (new \ReflectionProperty(StoreOutboxMessage::class, 'id'))->setValue($message, 42);

        self::assertSame(42, $message->getId());
    }
}
