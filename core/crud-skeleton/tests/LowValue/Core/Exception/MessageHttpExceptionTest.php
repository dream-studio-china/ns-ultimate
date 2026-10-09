<?php

namespace App\Tests\LowValue\Core\Exception;


use PHPUnit\Framework\Attributes\Group;
use App\Core\Exception\MessageErrorHttpException;
use App\Core\Exception\MessageSuccessHttpException;
use PHPUnit\Framework\TestCase;

#[Group('low-value')]
final class MessageHttpExceptionTest extends TestCase
{
    public function testMessageErrorHttpExceptionDefaults(): void
    {
        $e = new MessageErrorHttpException('forbidden', '/back');

        self::assertSame(403, $e->getStatusCode());
        self::assertSame('forbidden', $e->getMessage());
        self::assertSame('/back', $e->getHeaders()['redirectUrl']);
    }

    public function testMessageSuccessHttpExceptionDefaults(): void
    {
        $e = new MessageSuccessHttpException('ok', '/next');

        self::assertSame(200, $e->getStatusCode());
        self::assertSame('ok', $e->getMessage());
        self::assertSame('/next', $e->getHeaders()['redirectUrl']);
    }
}
