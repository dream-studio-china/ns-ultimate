<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Serializer\Normalizer;

use App\Core\Serializer\Normalizer\CircularReferenceHandler;
use PHPUnit\Framework\TestCase;

final class CircularReferenceHandlerTest extends TestCase
{
    public function testHandleReturnsScalarId(): void
    {
        $result = CircularReferenceHandler::handle(new class {
            public function getId(): int
            {
                return 42;
            }
        });

        self::assertSame(['id' => 42], $result);
    }

    public function testHandleReturnsStringId(): void
    {
        $result = CircularReferenceHandler::handle(new class {
            public function getId(): string
            {
                return 'abc-123';
            }
        });

        self::assertSame(['id' => 'abc-123'], $result);
    }

    public function testHandleReturnsNullForNonScalarId(): void
    {
        $result = CircularReferenceHandler::handle(new class {
            public function getId(): object
            {
                return new \stdClass();
            }
        });

        self::assertNull($result);
    }

    public function testHandleReturnsNullForArrayId(): void
    {
        $result = CircularReferenceHandler::handle(new class {
            public function getId(): array
            {
                return ['nested'];
            }
        });

        self::assertNull($result);
    }

    public function testHandleThrowsWhenGetIdIsMissing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Every entity should have `getId` method');

        CircularReferenceHandler::handle(new \stdClass());
    }
}
