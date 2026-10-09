<?php

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\Inflect;
use PHPUnit\Framework\TestCase;

final class InflectExtendedTest extends TestCase
{
    public function testPluralizeRegular(): void
    {
        self::assertSame('tests', Inflect::pluralize('test'));
        self::assertSame('categories', Inflect::pluralize('category'));
    }

    public function testPluralizeXChSh(): void
    {
        self::assertSame('boxes', Inflect::pluralize('box'));
        self::assertSame('churches', Inflect::pluralize('church'));
        self::assertSame('dishes', Inflect::pluralize('dish'));
    }

    public function testPluralizeIrregular(): void
    {
        self::assertSame('children', Inflect::pluralize('child'));
        self::assertSame('men', Inflect::pluralize('man'));
        self::assertSame('feet', Inflect::pluralize('foot'));
        self::assertSame('teeth', Inflect::pluralize('tooth'));
        self::assertSame('people', Inflect::pluralize('person'));
        self::assertSame('geese', Inflect::pluralize('goose'));
    }

    public function testPluralizeUncountable(): void
    {
        self::assertSame('sheep', Inflect::pluralize('sheep'));
        self::assertSame('fish', Inflect::pluralize('fish'));
        self::assertSame('deer', Inflect::pluralize('deer'));
        self::assertSame('money', Inflect::pluralize('money'));
        self::assertSame('information', Inflect::pluralize('information'));
        self::assertSame('equipment', Inflect::pluralize('equipment'));
    }

    public function testPluralizeEndsWithS(): void
    {
        self::assertSame('tests', Inflect::pluralize('tests'));
    }

    public function testSingularizeRegular(): void
    {
        self::assertSame('test', Inflect::singularize('tests'));
        self::assertSame('category', Inflect::singularize('categories'));
    }

    public function testSingularizeIrregular(): void
    {
        self::assertSame('child', Inflect::singularize('children'));
        self::assertSame('man', Inflect::singularize('men'));
        self::assertSame('foot', Inflect::singularize('feet'));
        self::assertSame('tooth', Inflect::singularize('teeth'));
        self::assertSame('person', Inflect::singularize('people'));
        self::assertSame('goose', Inflect::singularize('geese'));
    }

    public function testSingularizeUncountable(): void
    {
        self::assertSame('sheep', Inflect::singularize('sheep'));
        self::assertSame('fish', Inflect::singularize('fish'));
        self::assertSame('series', Inflect::singularize('series'));
    }

    public function testSingularizeEndsWithEs(): void
    {
        self::assertSame('box', Inflect::singularize('boxes'));
        self::assertSame('church', Inflect::singularize('churches'));
        self::assertSame('dish', Inflect::singularize('dishes'));
    }

    public function testPluralizeIfSingular(): void
    {
        self::assertSame('1 item', Inflect::pluralize_if(1, 'item'));
        self::assertSame('1 category', Inflect::pluralize_if(1, 'category'));
    }

    public function testPluralizeIfPlural(): void
    {
        self::assertSame('2 items', Inflect::pluralize_if(2, 'item'));
        self::assertSame('10 categories', Inflect::pluralize_if(10, 'category'));
        self::assertSame('0 items', Inflect::pluralize_if(0, 'item'));
    }

    public function testPluralizeIfIrregular(): void
    {
        self::assertSame('3 children', Inflect::pluralize_if(3, 'child'));
        self::assertSame('1 child', Inflect::pluralize_if(1, 'child'));
    }

    public function testPluralizeUSEnding(): void
    {
        self::assertSame('buses', Inflect::pluralize('bus'));
    }

    public function testSingularizeUSEnding(): void
    {
        self::assertSame('bus', Inflect::singularize('buses'));
    }

    public function testPluralizeOEnding(): void
    {
        self::assertSame('potatoes', Inflect::pluralize('potato'));
        self::assertSame('tomatoes', Inflect::pluralize('tomato'));
    }

    public function testPluralizeHive(): void
    {
        self::assertSame('hives', Inflect::pluralize('hive'));
    }

    public function testSingularizeHive(): void
    {
        self::assertSame('hive', Inflect::singularize('hives'));
    }

    public function testPluralizeAndSingularizeRoundTrip(): void
    {
        $words = ['category', 'box', 'church', 'bus', 'child', 'man', 'foot'];
        foreach ($words as $word) {
            $plural = Inflect::pluralize($word);
            $singular = Inflect::singularize($plural);
            self::assertSame($word, $singular, "Round trip failed for: $word -> $plural -> $singular");
        }
    }
}
