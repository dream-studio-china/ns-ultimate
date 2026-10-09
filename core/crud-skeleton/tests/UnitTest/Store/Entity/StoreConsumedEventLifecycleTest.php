<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\StoreConsumedEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StoreConsumedEventLifecycleTest extends TestCase
{
    #[Group('low-value')]
    public function testNewEventHasNoIdUntilPersisted(): void
    {
        $event = new StoreConsumedEvent(
            '2beed699-4e1b-4a49-af75-2e0b0f6db0fd',
            'trade.order.cancelled.v1',
            '96a1a1b2-4f86-44ff-94cb-41a1411ad0d8',
            str_repeat('a', 64),
        );

        self::assertNull($event->getId());
    }

    #[Group('low-value')]
    public function testIdBecomesAvailableAfterPersistenceIdAssignment(): void
    {
        $event = new StoreConsumedEvent('event-id', 'topic.v1', 'aggregate-id', 'hash');
        (new \ReflectionProperty(StoreConsumedEvent::class, 'id'))->setValue($event, 7);

        self::assertSame(7, $event->getId());
    }
}
