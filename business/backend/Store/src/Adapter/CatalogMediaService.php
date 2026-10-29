<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Adapter;

use App\Common\Entity\Media;
use App\Common\Service\MediaService;
use App\Common\Service\MediaServiceInterface;
use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\CatalogImage;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Core deletes physical media before flushing the row: guard store assets before delegating. */
#[AsDecorator(decorates: MediaService::class)]
final readonly class CatalogMediaService implements MediaServiceInterface
{
    public function __construct(
        #[AutowireDecorated] private MediaServiceInterface $inner,
        private EntityManagerInterface $em,
    ) {}

    public function remove($object): bool
    {
        $media = $this->inner->get($object);
        if ($media instanceof Media && $this->em->getRepository(CatalogImage::class)->findOneBy(['uuid' => $media->getUuid()]) !== null) return false;
        return $this->inner->remove($object);
    }

    public function createFromUpload(UploadedFile $file, ?string $storage = null, array $meta = [], ?User $owner = null): Media { return $this->inner->createFromUpload($file, $storage, $meta, $owner); }
    public function get($object, bool $directly = false) { return $this->inner->get($object, $directly); }
    public function list($object = null, $order = null, bool $disableRequest = true) { return $this->inner->list($object, $order, $disableRequest); }
    public function new() { return $this->inner->new(); }
    public function update($object, ?array $data = null, bool $noFlush = false) { return $this->inner->update($object, $data, $noFlush); }
    public function wrapInTransaction(callable $fn): mixed { return $this->inner->wrapInTransaction($fn); }
}
