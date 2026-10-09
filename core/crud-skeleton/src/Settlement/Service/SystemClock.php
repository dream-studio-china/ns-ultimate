<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Settlement\Port\ClockInterface;

/**
 * Injectable clock; defaults to the system clock, overridable in tests.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
