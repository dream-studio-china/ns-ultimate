<?php

declare(strict_types=1);

namespace App\Inventory\Message;

final readonly class ReservationReleaseRequestedMessage
{
    /** @param array<string, mixed> $envelope */
    public function __construct(public array $envelope)
    {
    }
}
