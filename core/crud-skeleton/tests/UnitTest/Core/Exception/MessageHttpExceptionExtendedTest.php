<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Exception;

use App\Core\Exception\MessageErrorHttpException;
use App\Core\Exception\MessageSuccessHttpException;
use PHPUnit\Framework\TestCase;

final class MessageHttpExceptionExtendedTest extends TestCase
{
    public function testMessageErrorHttpExceptionStatusCode(): void
    {
        $exception = new MessageErrorHttpException('');

        self::assertSame(403, $exception->getStatusCode());
        self::assertSame(0, $exception->getCode());
    }

    public function testMessageSuccessHttpExceptionStatusCode(): void
    {
        $exception = new MessageSuccessHttpException('');

        self::assertSame(200, $exception->getStatusCode());
        self::assertSame(0, $exception->getCode());
    }

    public function testMessageErrorHttpExceptionHeadersWithRedirectUrl(): void
    {
        $exception = new MessageErrorHttpException('Forbidden', '/login');

        $headers = $exception->getHeaders();
        self::assertArrayHasKey('redirectUrl', $headers);
        self::assertSame('/login', $headers['redirectUrl']);
    }

    public function testMessageErrorHttpExceptionHeadersWithoutRedirectUrl(): void
    {
        $exception = new MessageErrorHttpException('Forbidden');

        $headers = $exception->getHeaders();
        self::assertArrayHasKey('redirectUrl', $headers);
        self::assertNull($headers['redirectUrl']);
    }

    public function testMessageSuccessHttpExceptionHeadersWithRedirectUrl(): void
    {
        $exception = new MessageSuccessHttpException('Done', '/dashboard');

        $headers = $exception->getHeaders();
        self::assertArrayHasKey('redirectUrl', $headers);
        self::assertSame('/dashboard', $headers['redirectUrl']);
    }

    public function testMessageSuccessHttpExceptionHeadersWithoutRedirectUrl(): void
    {
        $exception = new MessageSuccessHttpException('Done');

        $headers = $exception->getHeaders();
        self::assertArrayHasKey('redirectUrl', $headers);
        self::assertNull($headers['redirectUrl']);
    }

    public function testMessageErrorHttpExceptionMessage(): void
    {
        $exception = new MessageErrorHttpException('Access denied');
        self::assertStringContainsString('Access denied', $exception->getMessage());
    }

    public function testMessageSuccessHttpExceptionMessage(): void
    {
        $exception = new MessageSuccessHttpException('Operation complete');
        self::assertStringContainsString('Operation complete', $exception->getMessage());
    }
}
