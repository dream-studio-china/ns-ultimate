<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

/** A server-side pricing scope for consolidating items that have already been ordered. */
final class SalesAvailabilityScope
{
    private bool $merging = false;

    public function isMerging(): bool
    {
        return $this->merging;
    }

    /**
     * @template T
     * @param callable(): T $calculate
     * @return T
     */
    public function forMerge(callable $calculate): mixed
    {
        $previous = $this->merging;
        $this->merging = true;
        try {
            return $calculate();
        } finally {
            $this->merging = $previous;
        }
    }
}
