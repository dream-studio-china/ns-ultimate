<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Staff;

use App\Common\Entity\Media;
use App\Common\Service\MediaServiceInterface;
use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use App\Storage\Service\MediaStorageRegistry;
use Doctrine\ORM\EntityManagerInterface;
use App\Store\Entity\Store;
use NsUltimate\Business\Store\Entity\CatalogImage;
use NsUltimate\Business\Store\Service\StaffCommandService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/media', name: 'store-media-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class MediaController extends RestController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaServiceInterface $media,
        private readonly MediaStorageRegistry $storage,
        private readonly StaffCommandService $commands,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, string $scopeId): JsonResponse
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store) throw new AccessDeniedException();
        $this->denyAccessUnlessGranted('store:catalog_image:create', $store);

        return $this->respond(function () use ($request, $store): array {
            $file = $request->files->get('file');
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()
                || $file->getSize() <= 0 || $file->getSize() > 2 * 1024 * 1024
                || !in_array($file->getMimeType(), ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new HttpException(422, 'invalid_image');
            }
            $dimensions = @getimagesize($file->getPathname());
            if ($dimensions === false || max($dimensions[0], $dimensions[1]) > 8192) throw new HttpException(422, 'invalid_image');
            $storage = $request->request->get('storage', 'local');
            if (!in_array($storage, ['local', 'qiniu'], true)) throw new HttpException(422, 'invalid_storage');
            $command = [
                'commandUuid' => $request->request->get('commandUuid'),
                'digest' => hash_file('sha256', $file->getPathname()),
                'storage' => $storage,
            ];
            $created = null;
            try {
                return $this->commands->run($store, $this->actor(), 'media.upload', $command, function () use ($store, $file, $storage, &$created): array {
                    $created = $this->media->createFromUpload($file, $storage, [], null);
                    $this->em->persist(new CatalogImage($store->getUuid(), $created));
                    return ['before' => [], 'result' => [
                        'uuid' => $created->getUuid(),
                        'filename' => $created->getFilename(),
                        'originalFilename' => $created->getOriginalFilename(),
                        'mimeType' => $created->getMimeType(),
                        'size' => $created->getSize(),
                        'path' => $created->getPath(),
                        'storage' => $created->getStorage(),
                        'url' => $created->getPath(),
                    ]];
                });
            } catch (\Throwable $error) {
                if ($created instanceof Media) {
                    try { $this->storage->get($created->getStorage())->delete($created->getPath()); }
                    catch (\Throwable) { $this->logger->warning('Rolled-back store media requires storage cleanup.'); }
                }
                throw $error;
            }
        }, 201);
    }

    private function actor(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw new AccessDeniedException();
        return $user->getUuid();
    }
}
