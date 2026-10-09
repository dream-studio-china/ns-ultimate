<?php

namespace App\Tests\LowValue\Core\Utils;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Utils\UUID;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class UUIDTest extends TestCase
{
    public function testV4ProducesValidUuid(): void
    {
        $uuid = UUID::v4();

        self::assertTrue(UUID::is_valid($uuid));
    }

    public function testV4cHasNoDashes(): void
    {
        $uuid = UUID::v4c();

        self::assertSame(32, strlen($uuid));
        self::assertStringNotContainsString('-', $uuid);
    }
}
