<?php

declare(strict_types=1);

namespace App\Storage\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface MediaStorageInterface
{
    public static function getName(): string;

    public function store(UploadedFile $file, string $name): string;

    public function delete(string $path): void;
}
