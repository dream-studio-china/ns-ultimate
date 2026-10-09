<?php

declare(strict_types=1);

namespace App\Settlement\Service\Money;

use Brick\Math\BigInteger;

/**
 * Distributes a funding amount to target posting units using the largest-remainder
 * method. Each allocation keeps its exact quantum, its floored posting amount, and
 * a remainder; whole units of residual are assigned deterministically.
 */
final class AllocationRoundingService
{
    /**
     * Input: allocationKey => exact quantum at scale $scale.
     * Output: allocationKey => posting unit at $postingScale, plus rounded remainder.
     *
     * @param array<string, string> $exactByKey
     * @return array<string, string>
     */
    public function distribute(array $exactByKey, string $fundingQuantum, int $scale, int $postingScale): array
    {
        $factor = BigInteger::of(10)->power(max(0, $scale - $postingScale));
        $fundingPosting = BigInteger::of($fundingQuantum)->quotient($factor);

        // Floor each exact amount to posting units and collect remainders.
        $posted = [];
        $remainders = [];
        foreach ($exactByKey as $key => $exact) {
            $exactB = BigInteger::of($exact);
            $unit = $exactB->quotient($factor);
            $remainder = $exactB->minus($unit->multipliedBy($factor));
            $posted[$key] = $unit;
            if ($remainder->isGreaterThan(BigInteger::zero())) {
                $remainders[$key] = $remainder;
            }
        }

        // Total already allocated in posting units.
        $allocated = BigInteger::zero();
        foreach ($posted as $amount) {
            $allocated = $allocated->plus($amount);
        }
        $residual = $fundingPosting->minus($allocated);

        if ($residual->isZero()) {
            return array_map(static fn (BigInteger $amount): string => $amount->toBase(10), $posted);
        }
        if ($residual->isLessThan(BigInteger::zero())) {
            throw new \DomainException('Allocation total exceeds funding posting amount');
        }

        // Assign residual whole units by descending remainder; tie-break by key ascending.
        uksort($remainders, function (string $a, string $b) use ($remainders) {
            $cmp = $remainders[$b]->compareTo($remainders[$a]);
            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });
        foreach (array_keys($remainders) as $key) {
            if ($residual->isZero()) {
                break;
            }
            $posted[$key] = $posted[$key]->plus(1);
            $residual = $residual->minus(1);
        }

        // A residual without a fractional allocation cannot be assigned fairly.
        if (!$residual->isZero()) {
            throw new \DomainException(sprintf('Undistributable residual of %s posting units', $residual->toBase(10)));
        }

        return array_map(static fn (BigInteger $amount): string => $amount->toBase(10), $posted);
    }
}
