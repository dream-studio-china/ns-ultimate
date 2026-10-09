<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testLoginWithUsername(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('user2@example.com');
        $user->setUsername('johnsmith');
        $user->setPassword($hasher->hashPassword($user, 'secret99'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'johnsmith',
            'password' => 'secret99',
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testLoginWithVerifiedPhone(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('phoneuser@example.com');
        $user->setUsername('phoneuser');
        $user->setPhone('+8613800000000');
        $user->setPhoneVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'phone123'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => '+8613800000000',
            'password' => 'phone123',
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testLoginFailsWithUnverifiedPhone(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('unverified@example.com');
        $user->setUsername('unverified');
        $user->setPhone('+8613900000000');
        $user->setPhoneVerified(false);
        $user->setPassword($hasher->hashPassword($user, 'pw'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => '+8613900000000',
            'password' => 'pw',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Group('low-value')]
    public function testLoginFailsWithInvalidPassword(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('wrongpw@example.com');
        $user->setUsername('wrongpw');
        $user->setPassword($hasher->hashPassword($user, 'correct'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'wrongpw@example.com',
            'password' => 'wrong-password',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginFailsWithNonexistentUser(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Group('low-value')]
    public function testEmptyCredentialsReturnBadRequest(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => '',
            'password' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }
}
