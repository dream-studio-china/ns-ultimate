<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Repository;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRepositoryTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Identity\\Entity\\User u')->execute();

        self::ensureKernelShutdown();
    }

    public function testFindByEmailAndUsernameNormalizeInput(): void
    {
        $this->createUser('RepoUser@Example.com', 'RepoUser', 'Pass123!', '+8613811111111', true);

        $client = static::createClient();
        $repo = $client->getContainer()->get(UserRepository::class);

        self::assertNotNull($repo->findByEmail('  repouser@example.com  '));
        self::assertNotNull($repo->findByUsername('  REPOUSER  '));
    }

    public function testFindByIdentifierRejectsUnverifiedPhone(): void
    {
        $this->createUser('phone1@example.com', 'phone1', 'Pass123!', '+8613822222222', false);

        $client = static::createClient();
        $repo = $client->getContainer()->get(UserRepository::class);

        self::assertNull($repo->findByIdentifier('+8613822222222'));
    }

    public function testFindByIdentifierAcceptsVerifiedPhoneAndEmailUsername(): void
    {
        $this->createUser('phone2@example.com', 'phone2', 'Pass123!', '+8613833333333', true);

        $client = static::createClient();
        $repo = $client->getContainer()->get(UserRepository::class);

        self::assertNotNull($repo->findByIdentifier('+8613833333333'));
        self::assertNotNull($repo->findByIdentifier('phone2@example.com'));
        self::assertNotNull($repo->findByIdentifier('phone2'));
    }

    private function createUser(string $email, string $username, string $plainPassword, ?string $phone, bool $phoneVerified): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPhone($phone);
        $user->setPhoneVerified($phoneVerified);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $em->persist($user);
        $em->flush();

        self::ensureKernelShutdown();
    }
}
