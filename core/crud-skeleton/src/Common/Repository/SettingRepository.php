<?php

namespace App\Common\Repository;

use App\Common\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Common\Entity\Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function findById(int $id): ?Setting
    {
        return $this->find($id);
    }

    public function findByKey(string $key): ?Setting
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * @return Setting[]
     */
    public function findByGroup(string $groupName): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.groupName = :groupName')
            ->setParameter('groupName', $groupName)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.key', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
