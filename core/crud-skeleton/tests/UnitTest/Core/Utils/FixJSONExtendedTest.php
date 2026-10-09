<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\FixJSON;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class FixJSONExtendedTest extends TestCase
{
    public function testFixJsonWithNestedObject(): void
    {
        $input = "{'name':'John','address':{'city':'NYC','zip':'10001'}}";
        $result = FixJSON::fixJSON($input);

        self::assertStringContainsString('"name"', $result);
        self::assertStringContainsString('"John"', $result);
        self::assertStringContainsString('"address"', $result);
        self::assertStringContainsString('"city"', $result);
        self::assertStringContainsString('"NYC"', $result);
    }

    public function testFixJsonWithArrayInsideObject(): void
    {
        $input = "{'tags':['php','symfony']}";
        $result = FixJSON::fixJSON($input);

        self::assertStringContainsString('"tags"', $result);
        self::assertStringContainsString('"php"', $result);
        self::assertStringContainsString('"symfony"', $result);
    }

    #[Group('low-value')]
    public function testFixJsonAlreadyValid(): void
    {
        $input = '{"key":"value"}';
        $result = FixJSON::fixJSON($input);

        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
        self::assertSame('value', $decoded['key']);
    }

    public function testGetJsonTypeWithStringLiteral(): void
    {
        $result = FixJSON::getJSONType('"hello"');
        self::assertFalse($result);
    }

    public function testGetJsonTypeWithNumber(): void
    {
        $result = FixJSON::getJSONType('123');
        self::assertFalse($result);
    }

    public function testGetJsonTypeWithBooleanTrue(): void
    {
        $result = FixJSON::getJSONType('true');
        self::assertFalse($result);
    }

    public function testGetJsonTypeWithBooleanFalse(): void
    {
        $result = FixJSON::getJSONType('false');
        self::assertFalse($result);
    }

    #[Group('low-value')]
    public function testGetJsonTypeWithNullLiteral(): void
    {
        $result = FixJSON::getJSONType('null');
        self::assertFalse($result);
    }

    #[Group('low-value')]
    public function testGetJsonTypeWithLeadingWhitespaceAndArray(): void
    {
        $result = FixJSON::getJSONType("  \n\t[1,2,3]");
        self::assertSame('array', $result);
    }

    #[Group('low-value')]
    public function testGetJsonTypeWithNestedObject(): void
    {
        $result = FixJSON::getJSONType('{"a":{"b":1}}');
        self::assertSame('object', $result);
    }

    public function testFixJsonWithSingleQuotedKeyOnly(): void
    {
        $input = "{'key': \"value\"}";
        $result = FixJSON::fixJSON($input);

        $decoded = json_decode($result, true);
        self::assertIsArray($decoded);
        self::assertSame('value', $decoded['key']);
    }

    #[Group('low-value')]
    public function testFixJsonWithEmptyString(): void
    {
        $result = FixJSON::fixJSON('');
        self::assertSame('', $result);
    }
}
