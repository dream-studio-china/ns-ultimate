<?php

declare(strict_types=1);

namespace App\Identity\Repository;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Profile>
 */
class ProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profile::class);
    }

    public function findById(int $id): ?Profile
    {
        return $this->find($id);
    }

    public function findByUser(User $user): ?Profile
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findByUserId(int $userId): ?Profile
    {
        return $this->findOneBy(['user' => $userId]);
    }

    /**
     * @return Profile[]
     */
    public function findByLevel(string $level): array
    {
        return $this->findBy(['level' => $level], ['joinedAt' => 'ASC']);
    }

    /**
     * @return Profile[]
     */
    public function findByLevelOrAbove(string $minLevel): array
    {
        $levels = [
            Profile::LEVEL_BRONZE => 0,
            Profile::LEVEL_SILVER => 1,
            Profile::LEVEL_GOLD => 2,
            Profile::LEVEL_PLATINUM => 3,
            Profile::LEVEL_DIAMOND => 4,
        ];

        $minRank = $levels[$minLevel] ?? 0;
        $eligible = array_keys(array_filter($levels, fn($rank) => $rank >= $minRank));

        return $this->createQueryBuilder('p')
            ->where('p.level IN (:levels)')
            ->setParameter('levels', $eligible)
            ->orderBy('p.joinedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
