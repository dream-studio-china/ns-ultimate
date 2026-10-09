<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use App\Store\Repository\MembershipRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<Membership> */
final class MembershipService extends BaseService implements MembershipServiceInterface
{
    public function __construct(ContainerInterface $container, private readonly MembershipRepository $membershipRepository)
    {
        parent::__construct($container, Membership::class);
    }

    public function grant(Store $store, string $userUuid, string $role): Membership
    {
        if (trim($userUuid) === '') {
            throw new \InvalidArgumentException('Store membership user UUID is required.');
        }

        $storeId = $store->getId();
        if ($storeId === null) {
            throw new \InvalidArgumentException('Store must be persisted before granting membership.');
        }

        return $this->wrapInTransaction(function () use ($storeId, $userUuid, $role): Membership {
            $managedStore = $this->getEntityManager()->getReference(Store::class, $storeId);
            \assert($managedStore instanceof Store);
            $membership = $this->membershipRepository->findForStoreAndUser($managedStore, $userUuid);
            if ($membership === null) {
                $membership = new Membership($managedStore, $userUuid, $role);
                $this->getEntityManager()->persist($membership);
                return $membership;
            }

            $membership->setRole($role)->activate();
            return $membership;
        });
    }

    public function isAuthorized(Store $store, string $userUuid, array $allowedRoles = []): bool
    {
        $membership = $this->membershipRepository->findForStoreAndUser($store, $userUuid);
        return $membership !== null
            && $membership->isActive()
            && ($allowedRoles === [] || in_array($membership->getRole(), $allowedRoles, true));
    }

    public function requireAuthorization(Store $store, string $userUuid, array $allowedRoles = []): Membership
    {
        $membership = $this->membershipRepository->findForStoreAndUser($store, $userUuid);
        if ($membership === null || !$membership->isActive() || ($allowedRoles !== [] && !in_array($membership->getRole(), $allowedRoles, true))) {
            throw new \RuntimeException('Store membership authorization denied.');
        }

        return $membership;
    }
}
