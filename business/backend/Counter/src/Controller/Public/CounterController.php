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

    /** @return array{uuid: string, storeUuid: string, code: string, name: string, capacity: ?int, sort: int, metadata: array<string, mixed>|null}|null */
    protected function detailResponse(?object $entity): ?array
    {
        if (!$entity instanceof Counter) {
            return null;
        }
        if ($this->storeService->findActiveByUuid($entity->getStoreUuid()) === null) {
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
        ];
    }
}
