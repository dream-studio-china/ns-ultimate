<?php

declare(strict_types=1);

namespace App\Storage\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class LocalStorage implements MediaStorageInterface
{
    public function __construct(
        private string $basePath,
        private string $baseUrl,
    ) {}

    public static function getName(): string
    {
        return 'local';
    }

    public function store(UploadedFile $file, string $name): string
    {
        $directory = date('Ym');
        $targetDirectory = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $directory;

        if (!is_dir($targetDirectory)) {
            set_error_handler(static fn(): bool => true);
            try {
                $created = mkdir($targetDirectory, 0775, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($targetDirectory)) {
                throw new \RuntimeException(sprintf('Unable to create upload directory "%s".', $targetDirectory));
            }
        }

        if (!is_dir($targetDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory "%s".', $targetDirectory));
        }

        $file->move($targetDirectory, $name);

        return rtrim($this->baseUrl, '/') . '/' . $directory . '/' . $name;
    }

    public function delete(string $path): void
    {
        $baseUrl = rtrim($this->baseUrl, '/');
        if (!str_starts_with($path, $baseUrl . '/')) {
            return;
        }

        $relativePath = ltrim(substr($path, strlen($baseUrl)), '/');
        $filePath = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $basePath = realpath($this->basePath);
        $realPath = realpath($filePath);

        if ($basePath === false || $realPath === false || !str_starts_with($realPath, $basePath . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_file($realPath) && !unlink($realPath)) {
            throw new \RuntimeException(sprintf('Unable to delete file "%s".', $realPath));
        }
    }
}
