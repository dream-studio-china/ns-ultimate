<?php

namespace App\Tests\UnitTest\Core\Serializer;

use App\Core\Serializer\CircularReferenceHandler;
use PHPUnit\Framework\TestCase;

final class CircularReferenceHandlerTest extends TestCase
{
    public function testHandleUsesEntityIdWhenAvailable(): void
    {
        $value = CircularReferenceHandler::handle(new class {
            public function getId(): int
            {
                return 5;
            }
        });

        self::assertSame('5', $value);
    }

    public function testHandleFallsBackToHashForPlainObject(): void
    {
        $value = CircularReferenceHandler::handle(new \stdClass());

        self::assertNotSame('', $value);
    }
}
