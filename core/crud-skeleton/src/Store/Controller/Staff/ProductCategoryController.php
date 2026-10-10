<?php

declare(strict_types=1);

namespace App\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use App\Store\Service\ProductCategoryServiceInterface;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/product-categories', name: 'store-product-categories-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class ProductCategoryController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'slug'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];

    public function __construct(
        protected readonly ProductCategoryServiceInterface $service,
        private readonly StoreServiceInterface $storeService,
        private readonly \App\Store\Repository\ProductCategoryRepository $categoryRepository,
    ) {}

    /** @return array<string, mixed> */
    protected function storeScopedFilter(Store $store): array { return ['store' => $store]; }
    protected function storeService(): StoreServiceInterface { return $this->storeService; }
    protected function storeAuthorizationResource(): string { return 'product_category'; }

    /** @param array<string, mixed> $content @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if ($entity instanceof ProductCategory) {
            $entity->setStore($this->storeForAuthorization());
            if (array_key_exists('parent', $content)) $entity->setParent($this->resolveParent($content['parent']));
        }
        unset($content['parent']);
        return $content;
    }

    /** @param array<string, mixed> $content @return array<string, mixed> */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if ($entity instanceof ProductCategory && array_key_exists('parent', $content)) {
            $entity->setParent($this->resolveParent($content['parent']));
        }
        unset($content['parent']);
        return $content;
    }

    private function resolveParent(mixed $value): ?ProductCategory
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('parent must be a category UUID, numeric id, or null.');
        $parent = is_int($value)
            ? $this->categoryRepository->find($value)
            : ($this->categoryRepository->findOneByUuid($value) ?? (ctype_digit($value) ? $this->categoryRepository->find((int) $value) : null));
        if ($parent === null || $parent->getStore()?->getUuid() !== $this->storeForAuthorization()->getUuid()) {
            throw new \InvalidArgumentException('Parent category must belong to this store.');
        }
        return $parent;
    }
}
