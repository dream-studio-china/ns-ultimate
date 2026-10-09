<?php

namespace App\Common\Repository;

use App\Common\Entity\Media;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Common\Entity\Media>
 */
class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    public function findById(int $id): ?Media
    {
        return $this->find($id);
    }

    /**
     * @return Media[]
     */
    public function findImages(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.mimeType LIKE :mime')
            ->setParameter('mime', 'image/%')
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
