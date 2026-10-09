<?php

declare(strict_types=1);

namespace App\Authorization\Repository;

use App\Authorization\Entity\RoleFieldGrant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoleFieldGrant>
 */
class RoleFieldGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoleFieldGrant::class);
    }

    public function findOneByRoleResourceAction(int $roleId, string $resource, string $action): ?RoleFieldGrant
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.role = :roleId')
            ->andWhere('g.resource = :resource')
            ->andWhere('g.action = :action')
            ->setParameter('roleId', $roleId)
            ->setParameter('resource', $resource)
            ->setParameter('action', $action)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $roleIds
     * @return list<RoleFieldGrant>
     */
    public function findByRoleIds(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->andWhere('g.role IN (:roleIds)')
            ->setParameter('roleIds', $roleIds)
            ->getQuery()
            ->getResult();
    }
}
