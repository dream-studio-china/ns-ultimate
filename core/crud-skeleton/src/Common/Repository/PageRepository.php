<?php

namespace App\Common\Repository;

use App\Common\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Common\Entity\Page>
 */
class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    public function findById(int $id): ?Page
    {
        return $this->find($id);
    }

    public function findBySlug(string $slug): ?Page
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return Page[]
     */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', 'published')
            ->orderBy('p.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
