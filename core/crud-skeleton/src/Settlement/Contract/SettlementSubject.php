<?php

declare(strict_types=1);

namespace App\Settlement\Contract;

final readonly class SettlementSubject
{
    public function __construct(
        public string $type,
        public string $id,
        public string $version,
    ) {
    }

    public function asString(): string
    {
        return sprintf('%s:%s', $this->type, $this->id);
    }
}
