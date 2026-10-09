<?php

declare(strict_types=1);

namespace App\Settlement\Port;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
