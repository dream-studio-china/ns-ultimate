<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Adapter;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use App\Store\Repository\ProductCategoryRepository;
use App\Store\Repository\ProductRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Service\ProductCategoryServiceInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reuses the core category CRUD actions while allowing an existing category to
 * change its store scope. The core controller is final, so this replacement
 * composes the same REST traits and keeps the original route contract.
 */
#[IsGranted('ROLE_ADMIN')]
final class ManageProductCategoryController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'slug'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled', 'store'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled', 'store'];

    public function __construct(
        protected readonly ProductCategoryServiceInterface $service,
        private readonly ProductCategoryRepository $categoryRepository,
        private readonly StoreRepository $storeRepository,
        private readonly ProductRepository $productRepository,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if ($entity instanceof ProductCategory) {
            $entity->setStore($this->resolveStore($content['store'] ?? null));
            if (array_key_exists('parent', $content)) {
                $entity->setParent($this->resolveParent($content['parent']));
            }
        }
        unset($content['store'], $content['parent']);
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof ProductCategory) {
            return $content;
        }

        $hasStore = array_key_exists('store', $content);
        $hasParent = array_key_exists('parent', $content);
        $store = $hasStore ? $this->resolveStore($content['store']) : $entity->getStore();
        $parent = $hasParent ? $this->resolveParent($content['parent']) : $entity->getParent();

        if ($hasStore) {
            $this->assertStoreChangeIsSafe($entity, $store, $parent);
        }

        // Detach before moving scope so a category can change store together
        // with its parent in one request.
        if ($hasStore && $hasParent) {
            $this->assignParent($entity, null);
        }
        if ($hasStore) {
            $entity->setStore($store);
        }
        if ($hasParent) {
            $this->assignParent($entity, $parent);
        }

        unset($content['store'], $content['parent']);
        return $content;
    }

    private function assertStoreChangeIsSafe(ProductCategory $category, ?Store $store, ?ProductCategory $parent): void
    {
        if ($category->getStore()?->getUuid() === $store?->getUuid()) {
            return;
        }
        if ($category->getChildren()->count() > 0) {
            throw new \InvalidArgumentException('A category with children cannot change store scope.');
        }
        if ($parent !== null && $parent->getStore()?->getUuid() !== $store?->getUuid()) {
            throw new \InvalidArgumentException('A category and its parent must have the same store scope.');
        }
        if ($store === null) {
            return;
        }
        foreach ($this->productRepository->findBy(['category' => $category]) as $product) {
            if ($product->getStore()?->getUuid() !== $store->getUuid()) {
                throw new \InvalidArgumentException('A category with products from another store cannot change store scope.');
            }
        }
    }

    /**
     * Doctrine rehydrates the category while initializing the inverse children
     * collection, which can restore the old parent after a detach. Load both
     * affected collections before changing the owning side.
     */
    private function assignParent(ProductCategory $category, ?ProductCategory $parent): void
    {
        $category->getParent()?->getChildren()->count();
        $parent?->getChildren()->count();
        $category->setParent($parent);
    }

    private function resolveStore(mixed $value): ?Store
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('store must be a UUID, numeric id, or null.');
        $store = is_int($value)
            ? $this->storeRepository->find($value)
            : ($this->storeRepository->findOneByUuid($value) ?? (ctype_digit($value) ? $this->storeRepository->find((int) $value) : null));
        if ($store === null) throw new \InvalidArgumentException('Store not found.');
        return $store;
    }

    private function resolveParent(mixed $value): ?ProductCategory
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('parent must be a category UUID, numeric id, or null.');
        $parent = is_int($value)
            ? $this->categoryRepository->find($value)
            : ($this->categoryRepository->findOneByUuid($value) ?? (ctype_digit($value) ? $this->categoryRepository->find((int) $value) : null));
        if ($parent === null) throw new \InvalidArgumentException('Parent category not found.');
        return $parent;
    }
}
