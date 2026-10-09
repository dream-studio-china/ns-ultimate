<?php

declare(strict_types=1);

namespace App\Store\Repository;

use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Membership> */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    public function findForStoreAndUser(Store $store, string $userUuid): ?Membership
    {
        return $this->findOneBy(['store' => $store, 'userUuid' => $userUuid]);
    }
}
