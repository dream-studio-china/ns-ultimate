<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Message;

use App\Inventory\Message\ReservationReleaseRequestedMessage;
use App\Inventory\Message\ReservationReleasedMessage;
use App\Inventory\Message\ReservationRequestedMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class InventoryMessageTest extends TestCase
{
    #[Group('low-value')]
    public function testMessagesExposeTheirEnvelope(): void
    {
        $envelope = ['eventId' => 'event'];
        self::assertSame($envelope, (new ReservationRequestedMessage($envelope))->envelope);
        self::assertSame($envelope, (new ReservationReleaseRequestedMessage($envelope))->envelope);
        self::assertSame($envelope, (new ReservationReleasedMessage($envelope))->envelope);
    }
}
