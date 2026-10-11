<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Repository\StoreRepository;
use NsUltimate\Business\Counter\Service\CounterService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/counters', name: 'manage-counters-')]
#[IsGranted('ROLE_ADMIN')]
final class CounterController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['storeUuid', 'code', 'name'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['storeUuid', 'code', 'name', 'capacity', 'sort', 'metadata', 'enabled'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['code', 'name', 'capacity', 'sort', 'metadata', 'enabled'];

    public function __construct(
        protected readonly CounterService $service,
        private readonly StoreRepository $storeRepository,
    )
    {
    }

    /** @param array<string, mixed> $content */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!is_string($content['storeUuid'] ?? null) || !UUID::is_valid($content['storeUuid'])) {
            throw new \InvalidArgumentException('storeUuid must be a valid UUID.');
        }
        if ($this->storeRepository->findOneByUuid($content['storeUuid']) === null) {
            throw new \InvalidArgumentException('Store does not exist.');
        }
        return $this->validateCounterFields($content);
    }

    /** @param array<string, mixed> $content */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        return $this->validateCounterFields($content);
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    private function validateCounterFields(array $content): array
    {
        foreach (['code', 'name'] as $property) {
            if (array_key_exists($property, $content) && (!is_string($content[$property]) || trim($content[$property]) === '')) {
                throw new \InvalidArgumentException(sprintf('%s cannot be empty.', $property));
            }
            if (isset($content[$property])) {
                $content[$property] = trim($content[$property]);
            }
        }
        if (isset($content['capacity']) && (!is_int($content['capacity']) || $content['capacity'] < 1)) {
            throw new \InvalidArgumentException('capacity must be a positive integer.');
        }
        if (isset($content['sort']) && (!is_int($content['sort']) || $content['sort'] < 0)) {
            throw new \InvalidArgumentException('sort must be a non-negative integer.');
        }
        if (array_key_exists('metadata', $content) && $content['metadata'] !== null && !is_array($content['metadata'])) {
            throw new \InvalidArgumentException('metadata must be an object or null.');
        }

        return $content;
    }
}
