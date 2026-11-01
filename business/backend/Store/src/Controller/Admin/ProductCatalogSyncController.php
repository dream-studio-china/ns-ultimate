<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Admin;

use NsUltimate\Business\Store\Service\ProductCatalogSyncService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/product-catalog-sync', name: 'manage-product-catalog-sync-')]
#[IsGranted('ROLE_ADMIN')]
final class ProductCatalogSyncController
{
    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request, ProductCatalogSyncService $service): JsonResponse
    {
        return $this->respond($request, $service, false);
    }

    #[Route('', name: 'sync', methods: ['POST'])]
    public function sync(Request $request, ProductCatalogSyncService $service): JsonResponse
    {
        return $this->respond($request, $service, true);
    }

    private function respond(Request $request, ProductCatalogSyncService $service, bool $apply): JsonResponse
    {
        try {
            $payload = $request->toArray();
            $source = $payload['sourceStoreUuid'] ?? null;
            $target = $payload['targetStoreUuid'] ?? null;
            if (!is_string($source) || !is_string($target)) {
                throw new \InvalidArgumentException('sourceStoreUuid and targetStoreUuid are required.');
            }
            $data = $apply
                ? $service->sync($source, $target)
                : $service->preview($source, $target);

            return new JsonResponse($data);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return new JsonResponse([
                'error' => $exception->getMessage(),
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'Product catalog sync failed.',
                'message' => 'Product catalog sync failed.',
            ], 500);
        }
    }
}
