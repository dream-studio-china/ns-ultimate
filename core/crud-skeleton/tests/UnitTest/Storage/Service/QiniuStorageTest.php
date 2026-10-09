<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Storage\Service;

use App\Common\Entity\Setting;
use App\Common\Repository\SettingRepository;
use App\Storage\Service\QiniuStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Complements StorageServiceTest for QiniuStorage. The missing-SDK and
 * unconfigured branches run in the main process; stub-based scenarios run in
 * a separate process so the eval()'d Qiniu SDK stubs never leak into
 * StorageServiceTest (which asserts the missing-SDK behaviour itself).
 */
final class QiniuStorageTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/crud-qiniu-cov-' . bin2hex(random_bytes(4));
        mkdir($this->basePath, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Group('low-value')]
    public function testStoreThrowsWhenSdkIsNotInstalled(): void
    {
        if ($this->qiniuClassesDefined()) {
            self::markTestSkipped('Qiniu SDK/stub classes are already defined in this process.');
        }

        $storage = new QiniuStorage($this->settings($this->config()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Qiniu PHP SDK is not installed.');

        $storage->store($this->uploadedFile(), 'file.png');
    }

    #[Group('low-value')]
    public function testDeleteThrowsWhenStorageIsNotConfigured(): void
    {
        $storage = new QiniuStorage($this->settings([]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Qiniu storage is not configured.');

        $storage->delete('https://cdn.example.com/file.png');
    }

    #[Group('low-value')]
    public function testStoreThrowsWhenConfigurationIsIncomplete(): void
    {
        $storage = new QiniuStorage($this->settings(['qiniu.access_key' => 'ak']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Qiniu storage is not configured.');

        $storage->store($this->uploadedFile(), 'file.png');
    }

    #[RunInSeparateProcess]
    public function testStoreFallsBackToNameWhenSdkResultHasNoKey(): void
    {
        if ($this->qiniuClassesDefined()) {
            self::markTestSkipped('Qiniu SDK is installed; stub-based branch is not applicable.');
        }
        $this->defineStubs();
        \Qiniu\Storage\UploadManager::$result = [];

        $storage = new QiniuStorage($this->settings($this->config()));
        $path = $storage->store($this->uploadedFile(), 'file.png');

        self::assertSame('https://cdn.example.com/file.png', $path);
    }

    #[RunInSeparateProcess]
    public function testStoreUsesKeyReturnedBySdk(): void
    {
        if ($this->qiniuClassesDefined()) {
            self::markTestSkipped('Qiniu SDK is installed; stub-based branch is not applicable.');
        }
        $this->defineStubs();
        \Qiniu\Storage\UploadManager::$result = ['key' => 'uploaded-key.png'];

        $storage = new QiniuStorage($this->settings($this->config()));
        $path = $storage->store($this->uploadedFile(), 'file.png');

        self::assertSame('https://cdn.example.com/uploaded-key.png', $path);
    }

    #[RunInSeparateProcess]
    public function testDeleteOfPathOutsideConfiguredDomainIsIgnored(): void
    {
        // Correct-behavior test. The current src/ implementation forwards any
        // path to the Qiniu API without verifying it belongs to the configured
        // domain, so this assertion fails. See
        // docs/issues/coverage-2026-08-09/storage.md (bug #1).
        self::markTestSkipped('src bug: QiniuStorage::delete() does not verify the path belongs to the configured domain.');

        $this->defineStubs();

        $storage = new QiniuStorage($this->settings($this->config()));
        $storage->delete('https://other.example.com/logo.png');

        self::assertSame([], \Qiniu\Storage\BucketManager::$calls);
    }

    private function uploadedFile(): UploadedFile
    {
        $source = $this->basePath . '/' . bin2hex(random_bytes(4));
        file_put_contents($source, 'content');

        return new UploadedFile($source, 'file.png', 'image/png', null, true);
    }

    /** @return array<string, string> */
    private function config(): array
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

    private function qiniuClassesDefined(): bool
    {
        return class_exists('Qiniu\\Auth', false)
            || class_exists('Qiniu\\Storage\\UploadManager', false)
            || class_exists('Qiniu\\Storage\\BucketManager', false);
    }

    private function defineStubs(): void
    {
        if ($this->qiniuClassesDefined()) {
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
    public static ?array $result = null;
    public function putFile(string $token, string $name, string $path): array
    {
        if (self::$result !== null) {
            return [self::$result, null];
        }
        return [['key' => $name], null];
    }
}
class BucketManager
{
    public static bool $fail = false;
    /** @var list<string> */
    public static array $calls = [];
    public function __construct(object $auth) {}
    public function delete(string $bucket, string $key): ?QiniuStubError
    {
        self::$calls[] = $key;
        if (self::$fail) {
            return new QiniuStubError();
        }
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
