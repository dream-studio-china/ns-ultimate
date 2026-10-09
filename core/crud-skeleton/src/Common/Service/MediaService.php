<?php

namespace App\Common\Service;

use App\Common\Entity\Category;
use App\Common\Entity\Media;
use App\Core\Service\BaseService;
use App\Identity\Entity\User;
use App\Storage\Service\MediaStorageRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Exception\ValidatorException;

/** @extends BaseService<\App\Common\Entity\Media> */
final class MediaService extends BaseService implements MediaServiceInterface
{
    /** @param string[] $allowedMimeTypes */
    public function __construct(
        ContainerInterface $container,
        private readonly MediaStorageRegistry $storageRegistry,
        #[Autowire('%media.storage.default%')]
        private readonly string $defaultStorage,
        #[Autowire('%media.upload.max_size%')]
        private readonly int $maxUploadSize,
        #[Autowire('%media.upload.allowed_mime_types%')]
        private readonly array $allowedMimeTypes,
    )
    {
        parent::__construct($container, Media::class);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function createFromUpload(UploadedFile $file, ?string $storage = null, array $meta = [], ?User $owner = null): Media
    {
        $this->validateUpload($file);

        $storageName = $storage ?: $this->defaultStorage;
        $driver = $this->storageRegistry->get($storageName);
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $filename = bin2hex(random_bytes(16)) . '.' . strtolower($extension);
        $originalFilename = $file->getClientOriginalName() ?: $filename;
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $size = (int) $file->getSize();
        $dimensions = $this->readImageDimensions($file);
        $path = $driver->store($file, $filename);

        $media = new Media(
            $filename,
            $originalFilename,
            $mimeType,
            $size,
            $path,
            $storageName,
        );

        if ($owner instanceof User) {
            $media->setUser($owner);
        }

        if (!empty($meta['category'])) {
            $category = $this->em->getRepository(Category::class)->find((int) $meta['category']);
            if (!$category instanceof Category) {
                throw new ValidatorException('Category is not found');
            }
            $media->setCategory($category);
        }

        $media
            ->setAlt(isset($meta['alt']) ? (string) $meta['alt'] : null)
            ->setTitle(isset($meta['title']) ? (string) $meta['title'] : null)
            ->setWidth(isset($meta['width']) ? (int) $meta['width'] : $dimensions[0])
            ->setHeight(isset($meta['height']) ? (int) $meta['height'] : $dimensions[1]);

        $this->em->persist($media);
        $this->em->flush();

        return $media;
    }

    public function remove($object): bool
    {
        $media = $this->get($object);
        if (!$media instanceof Media) {
            return false;
        }

        try {
            $this->storageRegistry->get($media->getStorage())->delete($media->getPath());
        } catch (\Throwable $exception) {
            $this->logger->warning('Media file delete failed: ' . $exception->getMessage());
        }

        $this->em->remove($media);

        try {
            $this->em->flush();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function validateUpload(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidatorException('Uploaded file is invalid');
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new ValidatorException('Uploaded file is empty');
        }

        if ($size > $this->maxUploadSize) {
            throw new ValidatorException('Uploaded file is too large');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || !in_array($mimeType, $this->allowedMimeTypes, true)) {
            throw new ValidatorException('Uploaded file MIME type is not allowed');
        }
    }

    /** @return array{0:?int, 1:?int} */
    private function readImageDimensions(UploadedFile $file): array
    {
        $mimeType = $file->getMimeType();
        if ($mimeType === null || !str_starts_with($mimeType, 'image/')) {
            return [null, null];
        }

        $dimensions = @getimagesize($file->getPathname());
        if ($dimensions === false) {
            return [null, null];
        }

        return [(int) $dimensions[0], (int) $dimensions[1]];
    }
}
