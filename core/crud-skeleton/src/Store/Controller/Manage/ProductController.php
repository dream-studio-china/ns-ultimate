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
use App\Store\Entity\Product;
use App\Store\Entity\Store;
use App\Store\Service\ProductServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/products', name: 'manage-products-')]
#[IsGranted('ROLE_ADMIN')]
class ProductController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'description', 'status', 'metadata', 'store'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'description', 'status', 'metadata', 'store'];

    public function __construct(
        protected readonly ProductServiceInterface $service,
        private readonly ?\App\Store\Repository\StoreRepository $storeRepository = null,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (array_key_exists('store', $content)) {
            $store = $this->resolveStore($content['store']);
            if ($entity instanceof Product) {
                $entity->setStore($store);
            }
            unset($content['store']);
        }
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (array_key_exists('store', $content)) {
            $store = $this->resolveStore($content['store']);
            if ($entity instanceof Product) {
                $entity->setStore($store);
            }
            unset($content['store']);
        }
        return $content;
    }

    private function resolveStore(mixed $value): ?Store
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('store must be a UUID string or null.');
        }
        if ($this->storeRepository === null) {
            throw new \RuntimeException('Store repository not configured.');
        }
        $store = $this->storeRepository->findOneBy(['uuid' => $value]);
        if ($store === null) {
            throw new \InvalidArgumentException(sprintf('Store %s not found.', $value));
        }
        return $store;
    }
}
