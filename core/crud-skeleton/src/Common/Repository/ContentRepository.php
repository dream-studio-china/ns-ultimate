<?php

namespace App\Common\Repository;

use App\Common\Entity\Content;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Common\Entity\Content>
 */
class ContentRepository extends ServiceEntityRepository implements ContentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Content::class);
    }

    /**
     * Find latest contents limited by $limit
     * @return Content[]
     */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findById(int $id): ?Content
    {
        return $this->find($id);
    }
}
