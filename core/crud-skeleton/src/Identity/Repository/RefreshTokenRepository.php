<?php

declare(strict_types=1);

namespace App\Identity\Repository;

use App\Identity\Entity\RefreshToken;
use App\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findValidByHash(string $hash): ?RefreshToken
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.refreshTokenHash = :hash')
            ->andWhere('r.revokedAt IS NULL')
            ->andWhere('r.expiresAt > :now')
            ->setParameter('hash', $hash)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Revoke all active refresh tokens for a given user.
     */
    public function revokeAllForUser(User $user): int
    {
        return $this->createQueryBuilder('r')
            ->update(RefreshToken::class, 'r')
            ->set('r.revokedAt', ':now')
            ->where('r.user = :user')
            ->andWhere('r.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Remove expired tokens for cleanup.
     */
    public function removeExpired(): int
    {
        return $this->createQueryBuilder('r')
            ->delete(RefreshToken::class, 'r')
            ->where('r.expiresAt <= :now')
            ->orWhere('r.revokedAt <= :oneMonthAgo')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('oneMonthAgo', new \DateTimeImmutable('-30 days'))
            ->getQuery()
            ->execute();
    }
}
