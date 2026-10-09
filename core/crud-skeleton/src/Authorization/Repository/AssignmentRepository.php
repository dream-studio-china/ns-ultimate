<?php

declare(strict_types=1);

namespace App\Authorization\Repository;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assignment>
 */
class AssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    public function findOneByUuid(string $uuid): ?Assignment
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findActiveAssignment(string $userUuid, Role $role, string $scopeType, ?string $scopeUuid): ?Assignment
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.userUuid = :userUuid')
            ->andWhere('a.role = :role')
            ->andWhere('a.scopeType = :scopeType')
            ->andWhere('a.revokedAt IS NULL')
            ->setParameter('userUuid', $userUuid)
            ->setParameter('role', $role)
            ->setParameter('scopeType', $scopeType);

        if ($scopeUuid === null) {
            $qb->andWhere('a.scopeUuid IS NULL');
        } else {
            $qb->andWhere('a.scopeUuid = :scopeUuid')
                ->setParameter('scopeUuid', $scopeUuid);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return list<Assignment>
     */
    public function findActiveByUser(string $userUuid): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.userUuid = :userUuid')
            ->andWhere('a.revokedAt IS NULL')
            ->setParameter('userUuid', $userUuid)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<string>
     */
    public function findActiveUserUuidsByRole(int $roleId): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.userUuid')
            ->andWhere('a.role = :roleId')
            ->andWhere('a.revokedAt IS NULL')
            ->setParameter('roleId', $roleId)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['userUuid'], $rows));
    }

    public function findAnyByUserRoleScope(string $userUuid, int $roleId, string $scopeType, ?string $scopeUuid): ?Assignment
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.userUuid = :userUuid')
            ->andWhere('a.role = :roleId')
            ->andWhere('a.scopeType = :scopeType')
            ->setParameter('userUuid', $userUuid)
            ->setParameter('roleId', $roleId)
            ->setParameter('scopeType', $scopeType);

        if ($scopeUuid === null) {
            $qb->andWhere('a.scopeUuid IS NULL');
        } else {
            $qb->andWhere('a.scopeUuid = :scopeUuid')
                ->setParameter('scopeUuid', $scopeUuid);
        }

        $qb->orderBy('a.id', 'DESC')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
