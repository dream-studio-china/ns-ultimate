<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class IntegrationWebTestCase extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        if (!class_exists(\App\Kernel::class, false)) {
            require_once dirname(__DIR__, 2) . '/src/Kernel.php';
        }

        return \App\Kernel::class;
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        $class = static::getKernelClass();
        $env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
        $debug = $options['debug'] ?? $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? true;

        return new $class($env, (bool) $debug);
    }

    /**
     * Creates a browser client with a valid JWT Bearer token injected so that
     * access_control rules requiring IS_AUTHENTICATED_FULLY are satisfied.
     * A persistent test user (testauth@example.com) is created in the DB if needed.
     */
    protected static function createAuthenticatedClient(array $options = [], array $server = []): KernelBrowser
    {
        $client = static::createClient($options, $server);
        $container = $client->getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        if ($user === null) {
            $user = new User();
            $user->setEmail('testauth@example.com');
            $user->setUsername('testauth');
            $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
            $user->setRoles(['ROLE_ADMIN']);
            $em->persist($user);
            $em->flush();
        }

        /** @var TokenManager $tokenManager */
        $tokenManager = $container->get(TokenManager::class);
        $accessToken = $tokenManager->createAccessToken($user);

        $client->setServerParameters(['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken]);

        return $client;
    }
}

