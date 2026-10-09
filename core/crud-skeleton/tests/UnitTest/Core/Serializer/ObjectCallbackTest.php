<?php

namespace App\Tests\UnitTest\Core\Serializer;

use App\Core\Serializer\Callbacks\ObjectCallback;
use PHPUnit\Framework\TestCase;

final class ObjectCallbackTest extends TestCase
{
    public function testHandleReturnsIdForObjectWithGetter(): void
    {
        $id = ObjectCallback::handle(new class {
            public function getId(): int
            {
                return 77;
            }
        });

        self::assertSame(77, $id);
    }

    public function testHandleReturnsNullForUnsupportedInput(): void
    {
        self::assertNull(ObjectCallback::handle(new class {}));
    }
}
