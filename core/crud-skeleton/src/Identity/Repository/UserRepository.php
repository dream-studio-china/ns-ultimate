<?php

declare(strict_types=1);

namespace App\Identity\Repository;

use App\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => mb_strtolower(trim($username))]);
    }

    public function findByPhone(string $phone): ?User
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    /**
     * Finds a user by identifier (email, username, or phone).
     * Phone-based lookup only returns users with phoneVerified=true.
     */
    public function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($this->looksLikePhone($identifier)) {
            $user = $this->findByPhone($identifier);

            return $user?->isPhoneVerified() ? $user : null;
        }

        return $this->findByEmail($identifier) ?? $this->findByUsername($identifier);
    }

    private function looksLikePhone(string $value): bool
    {
        // Matches +8613912345678 or 13912345678 patterns
        return (bool) preg_match('/^\+?[0-9]{7,20}$/', $value);
    }
}
