<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Service;

use App\Identity\Service\RedisOtpStorage;
use PHPUnit\Framework\TestCase;

/**
 * RedisOtpStorage wraps RedisAdapter::createConnection() (Predis, since the
 * phpredis extension is not loaded) which is lazy: the constructor never
 * touches the network. A real redis-server is not available in this
 * environment, so a minimal in-memory RESP server (Resources/fake_redis_server.php)
 * is started as a subprocess on a free localhost port and RedisOtpStorage is
 * pointed at it via its DSN. No src/ file is modified.
 */
final class RedisOtpStorageTest extends TestCase
{
    private static ?int $port = null;
    /** @var resource|null */
    private static $serverProcess = null;
    private static ?int $serverPid = null;
    private static ?string $pidFile = null;

    private RedisOtpStorage $storage;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::findFreePort();
        self::$pidFile = sys_get_temp_dir() . '/fake_redis_' . getmypid() . '.pid';

        $script = __DIR__ . '/Resources/fake_redis_server.php';
        $cmd = sprintf(
            '%s %s %d %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            self::$port,
            escapeshellarg(self::$pidFile),
        );

        $serverProcess = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );

        if ($serverProcess === false) {
            self::fail('Unable to start fake Redis RESP server.');
        }
        self::$serverProcess = $serverProcess;

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.5);
            if ($conn !== false) {
                fclose($conn);
                break;
            }
            usleep(50_000);
        }

        $pidContents = is_file(self::$pidFile) ? (int) trim((string) file_get_contents(self::$pidFile)) : null;
        if ($pidContents === null || $pidContents <= 0) {
            proc_terminate(self::$serverProcess);
            self::fail('Fake Redis RESP server did not come up on port ' . self::$port . '.');
        }
        self::$serverPid = $pidContents;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid !== null) {
            @posix_kill(self::$serverPid, SIGTERM);
        }
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
        }
        if (self::$pidFile !== null && is_file(self::$pidFile)) {
            @unlink(self::$pidFile);
        }
    }

    protected function setUp(): void
    {
        $this->storage = new RedisOtpStorage('redis://127.0.0.1:' . self::$port . '/0');
    }

    public function testSetexExistsGetDelTtlLifecycle(): void
    {
        $key = 'otp:' . uniqid('', true);

        self::assertFalse($this->storage->exists($key));
        self::assertFalse($this->storage->get($key));
        self::assertSame(-2, $this->storage->ttl($key));

        self::assertTrue($this->storage->setex($key, 30, '123456'));

        self::assertTrue($this->storage->exists($key));
        self::assertSame('123456', $this->storage->get($key));

        $ttl = $this->storage->ttl($key);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(30, $ttl);

        self::assertSame(1, $this->storage->del($key, $key . '_missing'));
        self::assertFalse($this->storage->exists($key));
        self::assertSame(-2, $this->storage->ttl($key));
    }

    public function testDelMultipleKeysReturnsDeletedCount(): void
    {
        $k1 = 'multi1:' . uniqid('', true);
        $k2 = 'multi2:' . uniqid('', true);

        $this->storage->setex($k1, 60, 'a');
        $this->storage->setex($k2, 60, 'b');

        self::assertSame(2, $this->storage->del($k1, $k2));
        self::assertSame(0, $this->storage->del($k1, $k2));
    }

    public function testGetMissingKeyReturnsFalse(): void
    {
        self::assertFalse($this->storage->get('missing:' . uniqid('', true)));
    }

    public function testTtlExpiredKeyBehavesLikeMissing(): void
    {
        $key = 'expired:' . uniqid('', true);
        self::assertTrue($this->storage->setex($key, 1, 'v'));

        self::assertTrue($this->storage->exists($key));
        usleep(1_100_000);
        self::assertFalse($this->storage->exists($key));
        self::assertSame(-2, $this->storage->ttl($key));
    }

    public function testErrorRepliesAreCoercedToSafeDefaults(): void
    {
        // The fake server answers err_* keys with a RESP error; Predis
        // (created with exceptions=false) surfaces these as error objects
        // rather than strings/ints, exercising the defensive coercions.
        self::assertFalse($this->storage->get('err_get'));
        self::assertSame(0, $this->storage->del('err_del'));
        self::assertSame(0, $this->storage->ttl('err_ttl'));
    }

    /**
     * Correct-behavior test for Bug #1 (see report): when the store answers
     * with an error, exists() must not report the key as present. The current
     * implementation casts the error object to bool(true), so this test is
     * skipped until src is fixed.
     */
    public function testExistsReturnsFalseWhenServerErrors(): void
    {
        self::markTestSkipped(
            'RedisOtpStorage::exists() coerces a RESP error to true (bug, see '
            . 'docs/issues/coverage-2026-08-09/identity-security.md).',
        );
        self::assertFalse($this->storage->exists('err_exists'));
    }

    private static function findFreePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            self::fail('Unable to allocate a free TCP port.');
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);

        if ($name === false) {
            self::fail('Unable to read allocated TCP port.');
        }

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
