<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Core\Utils;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Utils\Inflect;
use PHPUnit\Framework\TestCase;

/**
 * Covers the remaining fall-through branches of Inflect::singularize().
 *
 * Note: Inflect::pluralize() line 135 (`return $string;`) is unreachable — the
 * final plural rule `'/$/' => "s"` matches every string, so pluralize() always
 * returns a transformed string. See report.
 *
 * @see docs/issues/coverage-2026-08-09/core-utils-di.md
 */
#[Group('low-value')]
final class InflectCoverageTest extends TestCase
{
    public function testSingularizeReturnsInputWhenNoRuleMatches(): void
    {
        self::assertSame('apple', Inflect::singularize('apple'));
        self::assertSame('dog', Inflect::singularize('dog'));
    }

    public function testPluralizeCatchesAllStringsWithFinalRule(): void
    {
        // Even words that match no dedicated rule get the trailing "s".
        self::assertSame('apples', Inflect::pluralize('apple'));
        self::assertSame('dogs', Inflect::pluralize('dog'));
    }

    public function testSingularizeMixedRules(): void
    {
        self::assertSame('tomato', Inflect::singularize('tomatoes'));
        self::assertSame('movie', Inflect::singularize('movies'));
        self::assertSame('knife', Inflect::singularize('knives'));
    }
}
