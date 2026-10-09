<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Service;

use App\Identity\Service\NullOtpStorage;
use PHPUnit\Framework\TestCase;

final class NullOtpStorageTest extends TestCase
{
    public function testSetGetExistsAndTtl(): void
    {
        $storage = new NullOtpStorage();

        self::assertFalse($storage->exists('otp:k1'));
        self::assertFalse($storage->get('otp:k1'));
        self::assertSame(-2, $storage->ttl('otp:k1'));

        self::assertTrue($storage->setex('otp:k1', 30, 'payload'));
        self::assertTrue($storage->exists('otp:k1'));
        self::assertSame('payload', $storage->get('otp:k1'));

        $ttl = $storage->ttl('otp:k1');
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(30, $ttl);
    }

    public function testExpiredValueBehavesAsMissing(): void
    {
        $storage = new NullOtpStorage();
        $storage->setex('otp:expired', -1, 'payload');

        self::assertFalse($storage->exists('otp:expired'));
        self::assertFalse($storage->get('otp:expired'));
        self::assertSame(-2, $storage->ttl('otp:expired'));
    }

    public function testDelCountsOnlyExistingKeys(): void
    {
        $storage = new NullOtpStorage();
        $storage->setex('otp:k1', 10, 'v1');
        $storage->setex('otp:k2', 10, 'v2');

        self::assertSame(2, $storage->del('otp:k1', 'otp:k2', 'otp:k3'));
        self::assertFalse($storage->exists('otp:k1'));
        self::assertFalse($storage->exists('otp:k2'));
    }
}
