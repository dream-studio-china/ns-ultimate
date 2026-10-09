<?php

declare(strict_types=1);

namespace App\Settlement\Service\Money;

use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;

/**
 * A canonical base-10 integer amount expressed at a fixed scale (default 18).
 *
 * `quantum` is an exact integer string with no sign, no leading zeros (except "0"),
 * and no decimal separator. It represents `quantum / 10^scale` units of `currency`.
 */
final readonly class QuantumAmount
{
    public const DEFAULT_SCALE = 18;

    private function __construct(
        public string $quantum,
        public int $scale,
        public string $currency,
    ) {
    }

    public static function of(string $quantum, string $currency, int $scale = self::DEFAULT_SCALE): self
    {
        self::assertScale($scale);
        $normalized = self::normalize($quantum);
        return new self($normalized, $scale, strtoupper($currency));
    }

    public static function zero(string $currency, int $scale = self::DEFAULT_SCALE): self
    {
        return new self('0', $scale, strtoupper($currency));
    }

    /**
     * Build from a decimal string (e.g. "12.34") at the given scale.
     */
    public static function fromDecimal(string $decimal, string $currency, int $scale = self::DEFAULT_SCALE): self
    {
        self::assertScale($scale);
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $decimal)) {
            throw new \InvalidArgumentException("Invalid decimal amount: $decimal");
        }
        if (str_starts_with($decimal, '-')) {
            throw new \InvalidArgumentException('Decimal amount must be non-negative');
        }
        [$whole, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $frac = str_pad($frac, $scale, '0');
        if (strlen($frac) > $scale) {
            throw new \InvalidArgumentException("Decimal has more than $scale fractional digits: $decimal");
        }
        return new self(BigInteger::of($whole . $frac)->toBase(10), $scale, strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameUnit($other);
        $sum = $this->bigInteger()->plus($other->bigInteger());
        return new self($sum->toBase(10), $this->scale, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameUnit($other);
        $diff = $this->bigInteger()->minus($other->bigInteger());
        if ($diff->isLessThan(BigInteger::zero())) {
            throw new \DomainException('QuantumAmount cannot be negative');
        }
        return new self($diff->toBase(10), $this->scale, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->bigInteger()->isZero();
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameUnit($other);
        return $this->bigInteger()->isGreaterThan($other->bigInteger());
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameUnit($other);
        return $this->bigInteger()->isLessThan($other->bigInteger());
    }

    public function equals(self $other): bool
    {
        return $this->scale === $other->scale
            && $this->currency === $other->currency
            && $this->quantum === $other->quantum;
    }

    /**
     * Convert to a target posting integer at a coarser scale, flooring toward zero.
     * The factor is 10^(scale - postingScale). Requires postingScale <= scale.
     */
    public function toPostingMinor(int $postingScale): string
    {
        if ($postingScale < 0 || $postingScale > $this->scale) {
            throw new \DomainException("postingScale must be in [0, {$this->scale}]");
        }
        $factor = BigInteger::of(10)->power(self::exp($this->scale - $postingScale));
        $value = $this->bigInteger()->quotient($factor);
        return $value->toBase(10);
    }

    /**
     * The fractional remainder after flooring to postingScale, expressed back at scale.
     * remainder = integer value at postingScale scaled up minus original.
     */
    public function postingRemainder(int $postingScale): self
    {
        $factor = BigInteger::of(10)->power(self::exp($this->scale - $postingScale));
        $floored = $this->bigInteger()->quotient($factor)->multipliedBy($factor);
        $remainder = $this->bigInteger()->minus($floored);
        return new self($remainder->toBase(10), $this->scale, $this->currency);
    }

    public function bigInteger(): BigInteger
    {
        return BigInteger::of($this->quantum);
    }

    public function toDecimal(): string
    {
        $b = $this->bigInteger();
        if ($b->isZero()) {
            return '0';
        }
        $negative = $b->isLessThan(BigInteger::zero());
        $abs = $negative ? $b->negated()->toBase(10) : $b->toBase(10);
        if ($this->scale === 0) {
            return ($negative ? '-' : '') . $abs;
        }
        $padded = str_pad($abs, $this->scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$this->scale);
        $frac = substr($padded, -$this->scale);
        $frac = rtrim($frac, '0');
        $frac = $frac === '' ? '0' : $frac;
        return ($negative ? '-' : '') . rtrim($whole . ($frac === '0' ? '' : '.' . $frac), '.');
    }

    public static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > 18) {
            throw new \DomainException("Scale must be within 0..18, got $scale");
        }
    }

    private static function normalize(string $quantum): string
    {
        if ($quantum === '') {
            throw new \InvalidArgumentException('Quantum amount cannot be empty');
        }
        if (str_starts_with($quantum, '-')) {
            throw new \InvalidArgumentException('Quantum amount must be non-negative');
        }
        if (!ctype_digit($quantum)) {
            throw new \InvalidArgumentException("Invalid quantum amount: $quantum");
        }
        try {
            BigInteger::of($quantum);
        } catch (MathException) {
            throw new \InvalidArgumentException("Invalid quantum amount: $quantum");
        }
        return ltrim($quantum, '0') === '' ? '0' : ltrim($quantum, '0');
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->scale !== $other->scale || $this->currency !== $other->currency) {
            throw new \DomainException('QuantumAmount unit mismatch (scale/currency)');
        }
    }

    /**
     * Returns a non-negative exponent for BigInteger::power().
     *
     * @return int<0, max>
     */
    private static function exp(int $delta): int
    {
        return max(0, $delta);
    }
}
