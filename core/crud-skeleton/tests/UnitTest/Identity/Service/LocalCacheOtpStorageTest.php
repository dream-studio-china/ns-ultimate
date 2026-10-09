<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Service;

use App\Identity\Service\LocalCacheOtpStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class LocalCacheOtpStorageTest extends TestCase
{
    public function testSetexGetExistsAndTtlLifecycle(): void
    {
        $storage = new LocalCacheOtpStorage(new ArrayAdapter());

        self::assertFalse($storage->exists('otp:key'));
        self::assertFalse($storage->get('otp:key'));
        self::assertSame(-2, $storage->ttl('otp:key'));

        self::assertTrue($storage->setex('otp:key', 30, 'value'));
        self::assertTrue($storage->exists('otp:key'));
        self::assertSame('value', $storage->get('otp:key'));

        $ttl = $storage->ttl('otp:key');
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(30, $ttl);
    }

    public function testTtlReturnsMinusOneWhenMetaMissing(): void
    {
        $cache = new ArrayAdapter();
        $storage = new LocalCacheOtpStorage($cache);

        $dataKey = 'otp.data.' . sha1('otp:key');
        $item = $cache->getItem($dataKey);
        $item->set('value');
        $item->expiresAfter(20);
        $cache->save($item);

        self::assertSame(-1, $storage->ttl('otp:key'));
    }

    public function testSetexWithNonPositiveTtlUsesSafeMinimum(): void
    {
        $storage = new LocalCacheOtpStorage(new ArrayAdapter());
        self::assertTrue($storage->setex('otp:key', 0, 'value'));

        self::assertTrue($storage->exists('otp:key'));
        self::assertGreaterThan(0, $storage->ttl('otp:key'));
    }

    public function testDelReturnsDeletedCount(): void
    {
        $storage = new LocalCacheOtpStorage(new ArrayAdapter());
        $storage->setex('k1', 60, 'v1');

        self::assertSame(1, $storage->del('k1', 'k2'));
        self::assertSame(-2, $storage->ttl('k1'));
        self::assertFalse($storage->exists('k1'));
    }
}
