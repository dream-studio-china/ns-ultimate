<?php

namespace App\Tests\LowValue\Core\Utils;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Utils\Inflect;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class InflectTest extends TestCase
{
    public function testPluralizeAndSingularize(): void
    {
        self::assertSame('categories', Inflect::pluralize('category'));
        self::assertSame('category', Inflect::singularize('categories'));
    }

    public function testPluralizeIf(): void
    {
        self::assertSame('1 item', Inflect::pluralize_if(1, 'item'));
        self::assertSame('2 items', Inflect::pluralize_if(2, 'item'));
    }
}
