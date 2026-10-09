<?php

namespace App\Tests\LowValue\Core\Utils;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Utils\FilterDateTime;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class FilterDateTimeTest extends TestCase
{
    public function testGetReturnsDateTimeInstance(): void
    {
        $value = (new FilterDateTime())->get('2024-01-01 00:00:00', new \DateTimeZone('UTC'));

        self::assertInstanceOf(\DateTime::class, $value);
        self::assertSame('UTC', $value->getTimezone()->getName());
    }
}
