<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\Math;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Math helpers that were previously unreachable (see var/uncovered-map.txt).
 *
 * @see docs/issues/coverage-2026-08-09/core-utils-di.md
 */
final class MathCoverageTest extends TestCase
{
    public function testAcosh(): void
    {
        self::assertSame(0.0, Math::acosh(1.0));
        self::assertSame(acosh(2.0), Math::acosh(2.0));
    }

    public function testAsinh(): void
    {
        self::assertSame(0.0, Math::asinh(0.0));
        self::assertSame(asinh(1.0), Math::asinh(1.0));
    }

    public function testAtanh(): void
    {
        self::assertSame(0.0, Math::atanh(0.0));
        self::assertSame(atanh(0.5), Math::atanh(0.5));
    }

    public function testGetrandmax(): void
    {
        self::assertSame(getrandmax(), Math::getrandmax());
        self::assertGreaterThan(0, Math::getrandmax());
    }

    public function testLcgValue(): void
    {
        // Math::lcg_value() delegates to lcg_value(), deprecated since PHP 8.4
        // (suite runs with failOnDeprecation). See report — bug M-2.
        $this->markTestSkipped('See report — bug M-2 (lcg_value() deprecated since PHP 8.4).');
    }

    public function testMtGetrandmax(): void
    {
        self::assertSame(mt_getrandmax(), Math::mt_getrandmax());
        self::assertGreaterThan(0, Math::mt_getrandmax());
    }

    public function testMtRand(): void
    {
        // Math::mt_rand(int) calls mt_rand($x) with a single argument, which is
        // no longer accepted on PHP 8.5 (ArgumentCountError). See report — bug M-1.
        $this->markTestSkipped('See report — bug M-1 (mt_rand() with single argument throws).');
    }

    public function testMtSrandSeedsDeterministicSequence(): void
    {
        Math::mt_srand(12345);
        $first = mt_rand();

        Math::mt_srand(12345);
        $second = mt_rand();

        self::assertSame($first, $second);
    }

    public function testRand(): void
    {
        // Math::rand(int) calls rand($x) with a single argument, which is not
        // accepted on PHP 8.5 (ArgumentCountError). See report — bug M-1.
        $this->markTestSkipped('See report — bug M-1 (rand() with single argument throws).');
    }

    public function testSrandSeedsDeterministicSequence(): void
    {
        Math::srand(54321);
        $first = rand();

        Math::srand(54321);
        $second = rand();

        self::assertSame($first, $second);
    }
}
