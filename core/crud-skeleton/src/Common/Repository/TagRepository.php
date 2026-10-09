<?php

namespace App\Common\Repository;

use App\Common\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Common\Entity\Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function findById(int $id): ?Tag
    {
        return $this->find($id);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return Tag[]
     */
    public function findByNameLike(string $name): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.name LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
