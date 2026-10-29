<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\Tests;

use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class AdminUserBatchLimitTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testOversizedUserBatchIsRejectedBeforeHashing(): void
    {
        $client = self::createAuthenticatedClient();
        $users = array_map(static fn (int $index): array => [
            'email' => sprintf('batch-limit-%d@example.com', $index),
            'username' => sprintf('batch-limit-%d', $index),
            'password' => 'P@ssw0rd',
        ], range(1, 41));

        $client->jsonRequest('POST', '/api/v1/manage/users', $users);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('At most 40 users', $body['message']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $entityManager->getRepository(User::class)->count(['username' => 'batch-limit-1']));
    }

    public function testSmallBatchStillCreatesUsers(): void
    {
        $client = self::createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/v1/manage/users', [
            ['email' => 'batch-ok-1@example.com', 'username' => 'batch-ok-1', 'password' => 'P@ssw0rd'],
            ['email' => 'batch-ok-2@example.com', 'username' => 'batch-ok-2', 'password' => 'P@ssw0rd'],
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($entityManager->getRepository(User::class)->findOneBy(['email' => 'batch-ok-1@example.com']));
        self::assertNotNull($entityManager->getRepository(User::class)->findOneBy(['email' => 'batch-ok-2@example.com']));
    }
}
