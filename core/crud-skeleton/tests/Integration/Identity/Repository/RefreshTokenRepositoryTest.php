<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Repository;

use App\Identity\Entity\RefreshToken;
use App\Identity\Entity\User;
use App\Identity\Repository\RefreshTokenRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class RefreshTokenRepositoryTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Identity\\Entity\\RefreshToken r')->execute();
        $em->createQuery('DELETE FROM App\\Identity\\Entity\\User u')->execute();

        self::ensureKernelShutdown();
    }

    public function testFindValidByHashReturnsOnlyActiveNonExpiredToken(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $client->getContainer()->get(RefreshTokenRepository::class);

        $user = $this->createUser($em, 'active@example.com', 'active-user');

        $valid = new RefreshToken($user, 'hash-valid', new \DateTimeImmutable('+1 day'));
        $revoked = new RefreshToken($user, 'hash-revoked', new \DateTimeImmutable('+1 day'));
        $revoked->revoke();
        $expired = new RefreshToken($user, 'hash-expired', new \DateTimeImmutable('-1 day'));

        $em->persist($valid);
        $em->persist($revoked);
        $em->persist($expired);
        $em->flush();

        self::assertNotNull($repo->findValidByHash('hash-valid'));
        self::assertNull($repo->findValidByHash('hash-revoked'));
        self::assertNull($repo->findValidByHash('hash-expired'));
    }

    public function testRevokeAllForUserRevokesOnlyActiveTokensForThatUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $client->getContainer()->get(RefreshTokenRepository::class);

        $userA = $this->createUser($em, 'a@example.com', 'user-a');
        $userB = $this->createUser($em, 'b@example.com', 'user-b');

        $a1 = new RefreshToken($userA, 'hash-a1', new \DateTimeImmutable('+1 day'));
        $a2 = new RefreshToken($userA, 'hash-a2', new \DateTimeImmutable('+1 day'));
        $b1 = new RefreshToken($userB, 'hash-b1', new \DateTimeImmutable('+1 day'));

        $em->persist($a1);
        $em->persist($a2);
        $em->persist($b1);
        $em->flush();

        $affected = $repo->revokeAllForUser($userA);
        self::assertSame(2, $affected);

        $em->clear();

        self::assertNull($repo->findValidByHash('hash-a1'));
        self::assertNull($repo->findValidByHash('hash-a2'));
        self::assertNotNull($repo->findValidByHash('hash-b1'));
    }

    public function testRemoveExpiredRemovesExpiredAndOldRevokedTokens(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $client->getContainer()->get(RefreshTokenRepository::class);

        $user = $this->createUser($em, 'cleanup@example.com', 'cleanup-user');

        $expired = new RefreshToken($user, 'hash-expired-cleanup', new \DateTimeImmutable('-2 days'));
        $oldRevoked = new RefreshToken($user, 'hash-old-revoked', new \DateTimeImmutable('+30 days'));
        $recentRevoked = new RefreshToken($user, 'hash-recent-revoked', new \DateTimeImmutable('+30 days'));
        $active = new RefreshToken($user, 'hash-active-cleanup', new \DateTimeImmutable('+30 days'));

        $oldRevoked->revoke();
        $recentRevoked->revoke();

        $this->setRevokedAt($oldRevoked, new \DateTimeImmutable('-40 days'));
        $this->setRevokedAt($recentRevoked, new \DateTimeImmutable('-5 days'));

        $em->persist($expired);
        $em->persist($oldRevoked);
        $em->persist($recentRevoked);
        $em->persist($active);
        $em->flush();

        $removed = $repo->removeExpired();
        self::assertGreaterThanOrEqual(2, $removed);

        $em->clear();

        self::assertNull($repo->findOneBy(['refreshTokenHash' => 'hash-expired-cleanup']));
        self::assertNull($repo->findOneBy(['refreshTokenHash' => 'hash-old-revoked']));
        self::assertNotNull($repo->findOneBy(['refreshTokenHash' => 'hash-recent-revoked']));
        self::assertNotNull($repo->findOneBy(['refreshTokenHash' => 'hash-active-cleanup']));
    }

    private function createUser(EntityManagerInterface $em, string $email, string $username): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword('hashed-password');

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function setRevokedAt(RefreshToken $token, \DateTimeImmutable $revokedAt): void
    {
        $ref = new \ReflectionProperty(RefreshToken::class, 'revokedAt');
        $ref->setValue($token, $revokedAt);
    }
}
