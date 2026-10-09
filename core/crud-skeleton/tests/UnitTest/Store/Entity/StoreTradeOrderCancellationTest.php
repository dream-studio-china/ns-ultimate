<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\StoreTradeOrderCancellation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StoreTradeOrderCancellationTest extends TestCase
{
    #[Group('low-value')]
    public function testExposesCancellationFields(): void
    {
        $cancelledAt = new \DateTimeImmutable('2026-08-02 12:00:00');

        $cancellation = new StoreTradeOrderCancellation('trade-order-uuid', 'store-uuid', $cancelledAt);

        self::assertSame('trade-order-uuid', $cancellation->getTradeOrderUuid());
        self::assertSame('store-uuid', $cancellation->getStoreUuid());
        self::assertSame($cancelledAt, $cancellation->getCancelledAt());
    }
}
