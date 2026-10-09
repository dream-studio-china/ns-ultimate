<?php

namespace App\Common\Repository;

use App\Common\Entity\Comment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Common\Entity\Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    public function findById(int $id): ?Comment
    {
        return $this->find($id);
    }

    /**
     * @return Comment[]
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.entityType = :entityType')
            ->andWhere('c.entityId = :entityId')
            ->andWhere('c.status = :status')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('status', 'approved')
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Comment[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
