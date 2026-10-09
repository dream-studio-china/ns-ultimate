<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Storage\Service;

use App\Common\Entity\Setting;
use App\Common\Repository\SettingRepository;
use App\Storage\Service\LocalStorage;
use App\Storage\Service\MediaStorageRegistry;
use App\Storage\Service\QiniuStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StorageServiceTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/crud-storage-test-' . bin2hex(random_bytes(4));
        mkdir($this->basePath, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    public function testLocalStorageStoresAndDeletesFile(): void
    {
        $source = $this->basePath . '/source.txt';
        file_put_contents($source, 'content');

        $file = new UploadedFile($source, 'source.txt', 'text/plain', null, true);
        $storage = new LocalStorage($this->basePath, '/uploads');

        $path = $storage->store($file, 'stored.txt');

        self::assertSame('local', LocalStorage::getName());
        self::assertMatchesRegularExpression('#^/uploads/\d{6}/stored\.txt$#', $path);

        $stored = $this->basePath . '/' . date('Ym') . '/stored.txt';
        self::assertFileExists($stored);

        $storage->delete($path);
        self::assertFileDoesNotExist($stored);
    }

    public function testLocalStorageDeleteIgnoresForeignAndUnsafePaths(): void
    {
        $storage = new LocalStorage($this->basePath, '/uploads');
        $outside = dirname($this->basePath) . '/outside-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outside, 'outside');

        $storage->delete('/assets/file.txt');
        $storage->delete('/uploads/../' . basename($outside));

        self::assertFileExists($outside);
        unlink($outside);
    }

    public function testLocalStorageFailsWhenBasePathCannotBeCreated(): void
    {
        $source = $this->basePath . '/source.txt';
        file_put_contents($source, 'content');
        $file = new UploadedFile($source, 'source.txt', 'text/plain', null, true);
        $storage = new LocalStorage($this->basePath . '/blocked/file', '/uploads');

        mkdir($this->basePath . '/blocked');
        file_put_contents($this->basePath . '/blocked/file', 'not a directory');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to create upload directory');

        $storage->store($file, 'stored.txt');
    }

    public function testRegistryResolvesDriversAndReportsUnknownName(): void
    {
        $local = new LocalStorage($this->basePath, '/uploads');
        $registry = new MediaStorageRegistry([$local]);

        self::assertSame($local, $registry->get('local'));
        self::assertSame(['local'], $registry->names());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown media storage driver "missing".');

        $registry->get('missing');
    }

    public function testQiniuRequiresConfiguration(): void
    {
        $storage = new QiniuStorage($this->settings([]));
        $file = $this->uploadedFile('image/png');

        self::assertSame('qiniu', QiniuStorage::getName());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Qiniu storage is not configured.');

        $storage->store($file, 'file.png');
    }

    public function testQiniuRequiresSdkWhenConfigured(): void
    {
        if (is_file(dirname(__DIR__, 3) . '/vendor/qiniu/php-sdk/src/Qiniu/Auth.php')) {
            self::markTestSkipped('Qiniu SDK is installed; missing-SDK branch is not applicable.');
        }

        $storage = new QiniuStorage($this->settings($this->qiniuConfig()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Qiniu PHP SDK is not installed.');

        $storage->delete('https://cdn.example.com/file.png');
    }

    public function testQiniuStoresAndDeletesWithSdkStubs(): void
    {
        $this->defineQiniuStubs();

        $storage = new QiniuStorage($this->settings($this->qiniuConfig()));
        $path = $storage->store($this->uploadedFile('image/png'), 'file.png');

        self::assertSame('https://cdn.example.com/file.png', $path);

        $bucketManagerClass = 'Qiniu\\Storage\\BucketManager';

        $storage->delete('https://cdn.example.com/file.png');
        self::assertTrue($bucketManagerClass::$deleted);

        $storage->delete('https://cdn.example.com/');
    }

    public function testQiniuStoreAndDeleteErrorsAreReported(): void
    {
        $this->defineQiniuStubs();
        $uploadManagerClass = 'Qiniu\\Storage\\UploadManager';
        $bucketManagerClass = 'Qiniu\\Storage\\BucketManager';
        $uploadManagerClass::$fail = true;

        $storage = new QiniuStorage($this->settings($this->qiniuConfig()));

        try {
            $storage->store($this->uploadedFile('image/png'), 'file.png');
            self::fail('Expected qiniu upload failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Qiniu upload failed', $exception->getMessage());
        }

        $uploadManagerClass::$fail = false;
        $bucketManagerClass::$fail = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Qiniu delete failed');

        $storage->delete('https://cdn.example.com/file.png');
    }

    private function uploadedFile(string $mimeType): UploadedFile
    {
        $source = $this->basePath . '/' . bin2hex(random_bytes(4));
        file_put_contents($source, 'content');

        return new UploadedFile($source, 'file.png', $mimeType, null, true);
    }

    /** @return array<string, string> */
    private function qiniuConfig(): array
    {
        return [
            'qiniu.access_key' => 'ak',
            'qiniu.secret_key' => 'sk',
            'qiniu.bucket' => 'bucket',
            'qiniu.domain' => 'https://cdn.example.com',
        ];
    }

    /** @param array<string, string> $values */
    private function settings(array $values): SettingRepository
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository
            ->method('findByKey')
            ->willReturnCallback(static function (string $key) use ($values): ?Setting {
                if (!array_key_exists($key, $values)) {
                    return null;
                }

                return (new Setting($key))->setValue($values[$key]);
            });

        return $repository;
    }

    private function defineQiniuStubs(): void
    {
        if (class_exists('Qiniu\\Auth', false)) {
            return;
        }

        eval(<<<'PHP'
namespace Qiniu;
class Auth
{
    public function __construct(public string $accessKey, public string $secretKey) {}
    public function uploadToken(string $bucket): string { return 'token-' . $bucket; }
}
namespace Qiniu\Storage;
class QiniuStubError
{
    public function message(): string { return 'stub error'; }
}
class UploadManager
{
    public static bool $fail = false;
    public function putFile(string $token, string $name, string $path): array
    {
        return self::$fail ? [null, new QiniuStubError()] : [['key' => $name], null];
    }
}
class BucketManager
{
    public static bool $deleted = false;
    public static bool $fail = false;
    public function __construct(object $auth) {}
    public function delete(string $bucket, string $key): ?QiniuStubError
    {
        if (self::$fail) { return new QiniuStubError(); }
        self::$deleted = true;
        return null;
    }
}
PHP);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }

        rmdir($path);
    }
}
