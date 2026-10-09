<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\StringCase;
use PHPUnit\Framework\TestCase;

final class StringCaseExtendedTest extends TestCase
{
    public function testMultipleDashes(): void
    {
        self::assertSame('helloWorldFoo', StringCase::dashesToCamelCase('hello-world-foo'));
    }

    public function testMultipleDashesCapitalized(): void
    {
        self::assertSame('HelloWorldFoo', StringCase::dashesToCamelCase('hello-world-foo', true));
    }

    public function testSingleWordNoDash(): void
    {
        self::assertSame('hello', StringCase::dashesToCamelCase('hello'));
    }

    public function testSingleWordCapitalized(): void
    {
        self::assertSame('Hello', StringCase::dashesToCamelCase('hello', true));
    }

    public function testAlreadyCamelCase(): void
    {
        self::assertSame('helloWorld', StringCase::dashesToCamelCase('helloWorld'));
    }

    public function testAlreadyPascalCase(): void
    {
        self::assertSame('helloWorld', StringCase::dashesToCamelCase('HelloWorld'));
    }

    public function testNumbersInString(): void
    {
        self::assertSame('foo123Bar', StringCase::dashesToCamelCase('foo-123-bar'));
    }

    public function testUnderscoresNotAffected(): void
    {
        self::assertSame('hello_world', StringCase::dashesToCamelCase('hello_world'));
    }

    public function testEmptyString(): void
    {
        self::assertSame('', StringCase::dashesToCamelCase(''));
    }

    public function testEmptyStringCapitalized(): void
    {
        self::assertSame('', StringCase::dashesToCamelCase('', true));
    }

    public function testOnlyDashes(): void
    {
        self::assertSame('', StringCase::dashesToCamelCase('---'));
    }

    public function testLeadingDash(): void
    {
        self::assertSame('hello', StringCase::dashesToCamelCase('-hello'));
    }

    public function testTrailingDash(): void
    {
        self::assertSame('hello', StringCase::dashesToCamelCase('hello-'));
    }
}
