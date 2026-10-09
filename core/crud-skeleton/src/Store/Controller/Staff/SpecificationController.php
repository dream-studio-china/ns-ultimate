<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use App\Store\Service\ProductServiceInterface;
use App\Store\Service\SpecificationServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/products/{productUuid}/specifications', name: 'store-specifications-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}', 'productUuid' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class SpecificationController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'price'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'price', 'status', 'sort'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'price', 'status', 'sort'];

    public function __construct(
        protected readonly SpecificationServiceInterface $service,
        private readonly ProductServiceInterface $productService,
        private readonly StoreServiceInterface $storeService,
    ) {
    }

    /** @return array<string, mixed> */
    protected function storeScopedFilter(Store $store): array
    {
        $product = $this->storeProduct($store);

        return $product === null
            ? ['id' => -1]
            : ['product' => $product, 'isDeleted' => false];
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->storeService;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        $product = $this->storeProduct($this->storeForAuthorization());
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }
        if ($entity instanceof Specification) {
            $entity->setProduct($product);
        }

        return $content;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if (!$entity instanceof Specification) {
            return null;
        }

        $entity->setIsDeleted(true);
        $this->service->update($entity, []);

        return $this->success('', 'SUCCESS', 204);
    }

    private function storeProduct(Store $store): ?Product
    {
        $productUuid = $this->getRequestStack()->getCurrentRequest()?->attributes->get('productUuid');
        if (!is_string($productUuid) || $productUuid === '') {
            return null;
        }

        $product = $this->productService->get([
            'uuid' => $productUuid,
            'store' => $store,
            'isDeleted' => false,
        ], false);

        return $product instanceof Product ? $product : null;
    }
}
