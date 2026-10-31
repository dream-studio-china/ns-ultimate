<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\Public;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Store\Service\StoreServiceInterface;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Service\CounterService;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/counters', name: 'public-counters-')]
final class CounterController extends RestController
{
    use ApiView, DetailApiViewMixin;

    public function __construct(
        protected readonly CounterService $service,
        private readonly StoreServiceInterface $storeService,
    ) {
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        return ['enabled' => true];
    }

    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null
     */
    protected function detailFilter(array|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter = null)
    {
        // The public API only exposes UUID identifiers; numeric ids resolve to
        // an `id` criterion and must never match.
        if (is_array($filter) && array_key_exists('id', $filter)) {
            return null;
        }

        return $filter;
    }

    /** @return array{uuid: string, storeUuid: string, code: string, name: string, capacity: ?int, sort: int, metadata: array<string, mixed>|null, diningModes: array<string, mixed>|null}|null */
    protected function detailResponse(?object $entity): ?array
    {
        if (!$entity instanceof Counter) {
            return null;
        }
        $store = $this->storeService->findActiveByUuid($entity->getStoreUuid());
        if ($store === null) {
            return null;
        }

        return [
            'uuid' => $entity->getUuid(),
            'storeUuid' => $entity->getStoreUuid(),
            'code' => $entity->getCode(),
            'name' => $entity->getName(),
            'capacity' => $entity->getCapacity(),
            'sort' => $entity->getSort(),
            'metadata' => $entity->getMetadata(),
            'diningModes' => $this->publicDiningModes($store->getSettings() ?? []),
        ];
    }

    /**
     * The customer app needs the selected mode and its ordering rules before
     * authentication. Expose only that payload, never the rest of store settings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|null
     */
    private function publicDiningModes(array $settings): ?array
    {
        $config = $settings['diningModes'] ?? null;
        if (!is_array($config) || !is_array($config['modes'] ?? null)) {
            return null;
        }

        $modes = [];
        foreach ($config['modes'] as $mode) {
            if (!is_array($mode) || !is_string($mode['uuid'] ?? null) || !is_string($mode['name'] ?? null)) {
                continue;
            }
            $public = [];
            foreach (['uuid', 'name', 'enabled', 'priority', 'switchButtonLabel', 'availability', 'catalog', 'rules'] as $key) {
                if (array_key_exists($key, $mode)) {
                    $public[$key] = $mode[$key];
                }
            }
            $modes[] = $public;
        }

        return [
            'version' => is_int($config['version'] ?? null) ? $config['version'] : 1,
            'modes' => $modes,
        ];
    }
}
