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
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use App\Store\Service\ProductServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Store\Repository\ProductCategoryRepository;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/products', name: 'store-products-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class ProductController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'description', 'status', 'metadata', 'category'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'description', 'status', 'metadata', 'category'];

    public function __construct(
        protected readonly ProductServiceInterface $service,
        private readonly StoreServiceInterface $storeService,
        private readonly ProductCategoryRepository $categoryRepository,
    ) {
    }

    /** @return array<string, mixed> */
    protected function storeScopedFilter(Store $store): array
    {
        return ['store' => $store, 'isDeleted' => false];
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
        if ($entity instanceof Product) {
            $entity->setStore($this->storeForAuthorization());
            if (array_key_exists('category', $content)) {
                $entity->setCategory($this->resolveCategory($content['category']));
                unset($content['category']);
            }
        }

        return $content;
    }

    /** @param array<string, mixed> $content
     *  @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if ($entity instanceof Product && array_key_exists('category', $content)) {
            $entity->setCategory($this->resolveCategory($content['category']));
            unset($content['category']);
        }

        return $content;
    }

    private function resolveCategory(mixed $value): ?ProductCategory
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('category must be a UUID string or null.');
        }
        $category = $this->categoryRepository->findOneByUuid($value);
        $store = $this->storeForAuthorization();
        if ($category === null || !$category->isEnabled()) {
            throw new \InvalidArgumentException('Product category not found or disabled.');
        }
        if ($category->getStore() !== null && $category->getStore()->getUuid() !== $store->getUuid()) {
            throw new \InvalidArgumentException('Product category is not available to this store.');
        }
        return $category;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if (!$entity instanceof Product) {
            return null;
        }

        $entity->setIsDeleted(true);
        $this->service->update($entity, []);

        return $this->success('', 'SUCCESS', 204);
    }
}
