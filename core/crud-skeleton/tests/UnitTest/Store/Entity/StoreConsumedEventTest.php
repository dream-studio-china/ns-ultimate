<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\StoreConsumedEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StoreConsumedEventTest extends TestCase
{
    #[Group('low-value')]
    public function testStoresInboundEventAuditFields(): void
    {
        $event = new StoreConsumedEvent(
            '2beed699-4e1b-4a49-af75-2e0b0f6db0fd',
            'trade.order.created.v1',
            '96a1a1b2-4f86-44ff-94cb-41a1411ad0d8',
            str_repeat('a', 64),
        );

        self::assertSame('2beed699-4e1b-4a49-af75-2e0b0f6db0fd', $event->getEventId());
        self::assertSame('trade.order.created.v1', $event->getTopic());
        self::assertSame('96a1a1b2-4f86-44ff-94cb-41a1411ad0d8', $event->getAggregateId());
        self::assertSame(str_repeat('a', 64), $event->getPayloadHash());
        self::assertInstanceOf(\DateTimeImmutable::class, $event->getProcessedAt());
    }
}
