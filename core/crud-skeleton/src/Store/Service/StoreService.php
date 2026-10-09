<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\Store;
use App\Store\Repository\StoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<Store> */
final class StoreService extends BaseService implements StoreServiceInterface
{
    public function __construct(ContainerInterface $container, private readonly StoreRepository $storeRepository)
    {
        parent::__construct($container, Store::class);
    }

    public function createStore(string $code, string $name, string $timezone): Store
    {
        if (trim($code) === '' || trim($name) === '') {
            throw new \InvalidArgumentException('Store code and name are required.');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('Store timezone must be a valid IANA timezone.', 0, $exception);
        }

        return $this->wrapInTransaction(function () use ($code, $name, $timezone): Store {
            if ($this->storeRepository->findOneByCode($code) !== null) {
                throw new \LogicException('Store code is already in use.');
            }
            $store = new Store($code, $name, $timezone);
            $this->getEntityManager()->persist($store);
            return $store;
        });
    }

    public function findActiveByUuid(string $uuid): ?Store
    {
        $store = $this->storeRepository->findOneByUuid($uuid);
        return $store !== null && $store->isActive() ? $store : null;
    }
}
