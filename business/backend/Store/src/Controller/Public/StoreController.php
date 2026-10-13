<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Public;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/stores', name: 'public-stores-')]
final class StoreController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(protected readonly StoreServiceInterface $service)
    {
    }

    /** @return array<string, string> */
    protected function commonFilter(): array
    {
        return ['status' => Store::STATUS_ACTIVE];
    }

    /** @param mixed $entities
     * @return list<array{uuid: string, code: string, name: string, timezone: string, currency: string, contact: array<string, mixed>|null, address: array<string, mixed>|null}> */
    protected function listProcessor(mixed $entities): array
    {
        if ($entities instanceof \Doctrine\ORM\QueryBuilder) {
            $entities = $entities->getQuery()->getResult();
        }

        if (!is_iterable($entities)) {
            throw new \UnexpectedValueException('Store list must be iterable.');
        }

        $responses = [];
        foreach ($entities as $store) {
            if (!$store instanceof Store) {
                throw new \UnexpectedValueException('Store list contains an unexpected entity.');
            }
            $responses[] = self::storeResponse($store);
        }

        return $responses;
    }

    /** @return array{uuid: string, code: string, name: string, timezone: string, currency: string, contact: array<string, mixed>|null, address: array<string, mixed>|null}|null */
    protected function detailResponse(?object $entity): ?array
    {
        return $entity instanceof Store ? self::storeResponse($entity) : null;
    }

    /** @return array{uuid: string, code: string, name: string, timezone: string, currency: string, contact: array<string, mixed>|null, address: array<string, mixed>|null} */
    private static function storeResponse(Store $store): array
    {
        return [
            'uuid' => $store->getUuid(),
            'code' => $store->getCode(),
            'name' => $store->getName(),
            'timezone' => $store->getTimezone(),
            'currency' => $store->getCurrency(),
            'contact' => $store->getContact(),
            'address' => $store->getAddress(),
        ];
    }
}
