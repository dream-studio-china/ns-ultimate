<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Storage\Service;

use App\Storage\Service\LocalStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Complements StorageServiceTest with the remaining uncovered branches of
 * LocalStorage (var/uncovered-map.txt: LocalStorage 40,65).
 */
final class LocalStorageCoverageTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/crud-local-cov-' . bin2hex(random_bytes(4));
        mkdir($this->basePath, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Group('low-value')]
    public function testStoreThrowsWhenDirectoryIsStillMissingAfterMkdir(): void
    {
        // A custom stream wrapper reports is_dir() === false while mkdir()
        // reports success. This drives LocalStorage::store() past the
        // `$created`-truthy branch (line 34) into the second is_dir() guard
        // at line 39 -> line 40 throw. In production this is only reachable
        // via a mkdir()/is_dir() race (TOCTOU).
        $scheme = 'localcov' . bin2hex(random_bytes(2));
        self::assertTrue(stream_wrapper_register($scheme, LocalCoverageWrapper::class));

        try {
            $source = $this->basePath . '/source.txt';
            file_put_contents($source, 'content');
            $file = new UploadedFile($source, 'source.txt', 'text/plain', null, true);

            $storage = new LocalStorage($scheme . '://' . $this->basePath, '/uploads');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create upload directory');

            $storage->store($file, 'stored.txt');
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testDeleteThrowsWhenFileCannotBeUnlinked(): void
    {
        $month = date('Ym');
        $dir = $this->basePath . '/locked/' . $month;
        mkdir($dir, 0775, true);
        $target = $dir . '/stored.txt';
        file_put_contents($target, 'content');

        $storage = new LocalStorage($this->basePath . '/locked', '/uploads');

        // Removing write permission from the parent directory makes unlink()
        // fail for the current (non-root) user while is_file()/realpath() still
        // resolve the file, exercising the `!unlink($realPath)` branch (line 65).
        // LocalStorage::delete() calls unlink() without suppression, which emits
        // an E_WARNING before the RuntimeException (see report, observation).
        // The error handler below keeps the suite green under failOnWarning.
        chmod($dir, 0555);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to delete file');

            set_error_handler(static fn(): bool => true);
            try {
                $storage->delete('/uploads/' . $month . '/stored.txt');
            } finally {
                restore_error_handler();
            }
        } finally {
            chmod($dir, 0775);
        }
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

/**
 * Stream wrapper whose is_dir() always reports false while mkdir() reports
 * success, used to exercise the TOCTOU branch of LocalStorage::store().
 */
final class LocalCoverageWrapper
{
    public $context;

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }
}
