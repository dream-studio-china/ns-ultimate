<?php

namespace App\Tests\UnitTest\Core\Utils;

use App\Core\Utils\StringCase;
use PHPUnit\Framework\TestCase;

final class StringCaseTest extends TestCase
{
    public function testDashesToCamelCase(): void
    {
        self::assertSame('helloWorld', StringCase::dashesToCamelCase('hello-world'));
        self::assertSame('HelloWorld', StringCase::dashesToCamelCase('hello-world', true));
    }
}
