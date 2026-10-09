<?php

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\FixJSON;
use PHPUnit\Framework\TestCase;

final class FixJSONTest extends TestCase
{
    public function testGetJsonTypeObject(): void
    {
        self::assertSame('object', FixJSON::getJSONType('{"key": "value"}'));
    }

    public function testGetJsonTypeArray(): void
    {
        self::assertSame('array', FixJSON::getJSONType('[1, 2, 3]'));
    }

    public function testGetJsonTypeInvalid(): void
    {
        self::assertFalse(FixJSON::getJSONType('invalid json'));
    }

    public function testGetJsonTypeNull(): void
    {
        self::assertFalse(FixJSON::getJSONType('null'));
    }

    public function testGetJsonTypeEmptyString(): void
    {
        self::assertFalse(FixJSON::getJSONType(''));
    }

    public function testFixJsonConvertsSingleQuotes(): void
    {
        $input = "{'key': 'value'}";
        $expected = '{"key": "value"}';
        self::assertSame($expected, FixJSON::fixJSON($input));
    }

    public function testFixJsonPreservesDoubleQuotes(): void
    {
        $input = '{"key": "value"}';
        self::assertSame($input, FixJSON::fixJSON($input));
    }

    public function testFixJsonHandlesEmpty(): void
    {
        self::assertSame('', FixJSON::fixJSON(''));
    }

    public function testFixJsonHandlesBareString(): void
    {
        self::assertSame('hello world', FixJSON::fixJSON('hello world'));
    }

    public function testGetJsonTypeWithLeadingWhitespace(): void
    {
        self::assertSame('object', FixJSON::getJSONType('  {"key":"value"}'));
    }

    public function testGetJsonTypeWithLeadingWhitespaceArray(): void
    {
        self::assertSame('array', FixJSON::getJSONType("  \n[1,2]"));
    }

    public function testFixJsonMixedContent(): void
    {
        $input = "{'name': 'John', 'age': 30}";
        $expected = '{"name": "John", "age": 30}';
        self::assertSame($expected, FixJSON::fixJSON($input));
    }
}
