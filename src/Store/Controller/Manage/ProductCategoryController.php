<?php

declare(strict_types=1);

namespace App\Store\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Entity\ProductCategory;
use App\Store\Repository\ProductCategoryRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Service\ProductCategoryServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/product-categories', name: 'manage-product-categories-')]
#[IsGranted('ROLE_ADMIN')]
final class ProductCategoryController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'slug'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled', 'store'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];

    public function __construct(
        protected readonly ProductCategoryServiceInterface $service,
        private readonly ProductCategoryRepository $categoryRepository,
        private readonly StoreRepository $storeRepository,
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
        if ($entity instanceof ProductCategory) {
            if (array_key_exists('store', $content)) {
                $entity->setStore($this->resolveStore($content['store']));
            }
            if (array_key_exists('parent', $content)) {
                $entity->setParent($this->resolveParent($content['parent']));
            }
        }
        unset($content['store'], $content['parent']);
        return $content;
    }

    private function resolveStore(mixed $value): ?\App\Store\Entity\Store
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
