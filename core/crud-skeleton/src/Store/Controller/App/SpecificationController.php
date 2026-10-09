<?php

declare(strict_types=1);

namespace App\Store\Controller\App;

use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Service\SpecificationServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/specifications', name: 'app-specifications-')]
class SpecificationController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly SpecificationServiceInterface $service,
        private readonly ?StoreContextResolverInterface $storeContextResolver = null,
        private readonly ?\App\Store\Repository\StoreRepository $storeRepository = null,
        private readonly ?\App\Store\Repository\ProductRepository $productRepository = null,
    ) {
    }

    /** @return array<string, mixed>|DqlExpression */
    protected function commonFilter(): array|DqlExpression
    {
        $baseValues = [
            'specStatus' => 'active',
            'specIsDeleted' => false,
            'productStatus' => 'active',
            'productIsDeleted' => false,
        ];

        try {
            $storeContext = $this->storeContextResolver?->resolve();
        } catch (\Throwable) {
            $storeContext = null;
        }

        if ($storeContext === null || $this->storeRepository === null) {
            return new DqlExpression(
                'entity.getStatus() == specStatus && entity.getIsDeleted() == specIsDeleted && entity.getProduct().getStatus() == productStatus && entity.getProduct().getIsDeleted() == productIsDeleted && !entity.getProduct().getStore()',
                $baseValues
            );
        }

        try {
            $store = $this->storeRepository->findOneBy(['uuid' => $storeContext->storeUuid]);
            if ($store === null) {
                return new DqlExpression(
                    'entity.getStatus() == specStatus && entity.getIsDeleted() == specIsDeleted && entity.getProduct().getStatus() == productStatus && entity.getProduct().getIsDeleted() == productIsDeleted && !entity.getProduct().getStore()',
                    $baseValues
                );
            }

            return new DqlExpression(
                'entity.getStatus() == specStatus && entity.getIsDeleted() == specIsDeleted && entity.getProduct().getStatus() == productStatus && entity.getProduct().getIsDeleted() == productIsDeleted && (!entity.getProduct().getStore() || entity.getProduct().getStore() == store)',
                array_merge($baseValues, ['store' => $store])
            );
        } catch (\Throwable) {
            return new DqlExpression(
                'entity.getStatus() == specStatus && entity.getIsDeleted() == specIsDeleted && entity.getProduct().getStatus() == productStatus && entity.getProduct().getIsDeleted() == productIsDeleted && !entity.getProduct().getStore()',
                $baseValues
            );
        }
    }

    #[Route('/by-product/{productId<\d+>}', name: 'by-product', methods: ['GET'])]
    public function listByProductAction(int $productId): Response
    {
        // Enforce product visibility before exposing its specifications.
        if ($this->productRepository !== null) {
            $product = $this->productRepository->find($productId);
            if ($product === null || $product->getIsDeleted() || !$product->isActive()) {
                return $this->success([]);
            }
            $productStore = $product->getStore();
            try {
                $storeContext = $this->storeContextResolver?->resolve();
            } catch (\Throwable) {
                $storeContext = null;
            }
            if ($storeContext === null) {
                if ($productStore !== null) {
                    return $this->success([]);
                }
            } else {
                if ($productStore !== null) {
                    try {
                        $store = $this->storeRepository?->findOneBy(['uuid' => $storeContext->storeUuid]);
                        if ($store === null || $productStore->getId() !== $store->getId()) {
                            return $this->success([]);
                        }
                    } catch (\Throwable) {
                        return $this->success([]);
                    }
                }
            }
        }

        return $this->success(
            $this->service->list(['product' => $productId, 'status' => 'active', 'isDeleted' => false], null, false)
        );
    }
}
