<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Core\Utils\UUID;
use App\Store\Entity\StoreOutboxMessage;
use PHPUnit\Framework\TestCase;

final class StoreOutboxMessageTest extends TestCase
{
    public function testTracksPublicationAndRetryMetadata(): void
    {
        $message = new StoreOutboxMessage('store.order.accepted.v1', 'store_order', 'order-uuid', ['orderUuid' => 'order-uuid']);

        self::assertTrue(UUID::is_valid($message->getEventId()));
        self::assertSame('store.order.accepted.v1', $message->getTopic());
        self::assertSame('store_order', $message->getAggregateType());
        self::assertSame('order-uuid', $message->getAggregateId());
        self::assertSame(['orderUuid' => 'order-uuid'], $message->getPayload());
        self::assertFalse($message->isPublished());

        $nextAttempt = new \DateTimeImmutable('+1 minute');
        $message->recordAttempt('temporary failure', $nextAttempt);
        self::assertSame(1, $message->getAttempts());
        self::assertSame('temporary failure', $message->getLastError());
        self::assertSame($nextAttempt, $message->getAvailableAt());

        $message->markPublished();
        self::assertTrue($message->isPublished());
        self::assertNotNull($message->getPublishedAt());
    }
}
