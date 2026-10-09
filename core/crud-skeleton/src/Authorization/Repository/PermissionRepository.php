<?php

declare(strict_types=1);

namespace App\Authorization\Repository;

use App\Authorization\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Permission>
 */
class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    public function findOneByCode(string $code): ?Permission
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @param list<string> $codes
     * @return list<Permission>
     */
    public function findByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();
    }
}
