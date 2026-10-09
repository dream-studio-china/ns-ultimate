<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authorization;

use App\Authorization\Command\SeedAuthorizationCommand;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AuthorizationContentPilotTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $ref = new \ReflectionProperty(\Symfony\Bundle\FrameworkBundle\Test\KernelTestCase::class, 'booted');
        $ref->setValue(null, false);
    }

    private function seedAuthorization(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $registry = $container->get(\App\Authorization\Service\AuthorizationResourceRegistry::class);
        $command = new SeedAuthorizationCommand($em, $registry);
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();
        $command->run($input, $output);
    }

    public function testContentPilotScenario(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);

        $adminToken = $this->createAdminAndGetToken($client);
        $admin = $this->findUserByEmail('testadmin@example.com');

        $storeXUuid = $this->createStore($client, $adminToken, 'store-x', 'Store X');
        $storeYUuid = $this->createStore($client, $adminToken, 'store-y', 'Store Y');
        self::assertNotNull($storeXUuid);
        self::assertNotNull($storeYUuid);

        $userA = $this->createUser('userA@pilot.test', 'userA');
        $userB = $this->createUser('userB@pilot.test', 'userB');
        $userC = $this->createUser('userC@pilot.test', 'userC');
        $this->assertGlobalAssignmentIsUnique($userC);

        $tokenA = $this->loginAndGetToken('userA@pilot.test', 'P@ssw0rd', $client);
        $tokenB = $this->loginAndGetToken('userB@pilot.test', 'P@ssw0rd', $client);
        $tokenC = $this->loginAndGetToken('userC@pilot.test', 'P@ssw0rd', $client);

        $this->grantMembership($client, $adminToken, $storeXUuid, $userA->getUuid(), 'manager');
        $this->grantMembership($client, $adminToken, $storeXUuid, $userB->getUuid(), 'manager');
        $this->grantMembership($client, $adminToken, $storeXUuid, $admin->getUuid(), 'owner');
        $this->grantMembership($client, $adminToken, $storeYUuid, $admin->getUuid(), 'owner');

        $editorRoleUuid = $this->grantAssignment($client, $adminToken, $userA->getUuid(), 'store_content_editor', 'store', $storeXUuid);
        $this->grantAssignment($client, $adminToken, $userB->getUuid(), 'store_content_metadata_editor', 'store', $storeXUuid);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/update', $editorRoleUuid), [
            'fields' => ['title', 'body', 'category', 'tags', 'metadata'],
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->request('DELETE', sprintf('/api/v1/manage/roles/%s', $editorRoleUuid));
        self::assertResponseStatusCodeSame(403);

        // Field-grant pilot (metadata): Content is not Store-scoped, only metadata field distinguishes roles
        $container = static::getContainer();
        $emForAuth = $container->get(EntityManagerInterface::class);
        $assignmentRepo = $emForAuth->getRepository(\App\Authorization\Entity\Assignment::class);
        $fieldGrantRepo = $emForAuth->getRepository(\App\Authorization\Entity\RoleFieldGrant::class);
        $registry = $container->get(\App\Authorization\Service\AuthorizationResourceRegistry::class);
        $cache = $container->get('cache.app');
        $authServiceForField = new \App\Authorization\Service\AuthorizationService($assignmentRepo, $cache);
        $fieldAuth = new \App\Authorization\Service\FieldAuthorizationService($assignmentRepo, $fieldGrantRepo, $registry, $authServiceForField);
        $scope = new \App\Authorization\Service\AuthorizationScope('store', $storeXUuid);
        $schema = ['title', 'body', 'category', 'tags', 'metadata'];

        // User A: metadata denied
        try {
            $fieldAuth->filterWritableFields($userA, 'common:content', 'update', ['title' => 'X Updated by A', 'metadata' => ['foo' => 'bar']], $schema, $scope);
            self::fail('User A with basic editor should not be allowed to update metadata');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        // User A: without metadata -> allowed
        $filtered = $fieldAuth->filterWritableFields($userA, 'common:content', 'update', ['title' => 'X Updated by A', 'body' => 'new body A'], $schema, $scope);
        self::assertSame(['title' => 'X Updated by A', 'body' => 'new body A'], $filtered);

        // User B: with metadata -> allowed
        $filtered = $fieldAuth->filterWritableFields($userB, 'common:content', 'update', ['title' => 'X Updated by B', 'metadata' => ['key' => 'valueB']], $schema, $scope);
        self::assertSame(['title' => 'X Updated by B', 'metadata' => ['key' => 'valueB']], $filtered);

        // User C (no assignment) cannot be authorized for common:content:create
        $scopeCreate = new \App\Authorization\Service\AuthorizationScope('store', $storeXUuid);
        $authService = $authServiceForField;
        self::assertFalse($authService->can($userC, 'common:content:create', $scopeCreate));

        $this->grantAssignment($client, $adminToken, $userC->getUuid(), 'store_content_editor', 'store', $storeXUuid);
        $assignment = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(\App\Authorization\Entity\Assignment::class)
            ->findOneBy(['userUuid' => $userC->getUuid(), 'scopeType' => 'store', 'revokedAt' => null]);
        self::assertInstanceOf(\App\Authorization\Entity\Assignment::class, $assignment);
        $client->request('GET', '/api/v1/manage/assignments/'.$assignment->getUuid());
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('PUT', '/api/v1/manage/assignments/'.$assignment->getUuid(), [
            'scopeUuid' => $storeXUuid,
        ]);
        self::assertResponseStatusCodeSame(200);
        $client->request('DELETE', '/api/v1/manage/assignments/'.$assignment->getUuid());
        self::assertResponseStatusCodeSame(204);

        // After revoke, userC loses common:content:create in that store scope
        self::assertFalse($authService->can($userC, 'common:content:create', $scopeCreate));
        try {
            $fieldAuth->filterWritableFields($userC, 'common:content', 'update', ['title' => 'C Revoked Attempt'], $schema, $scope);
            self::fail('Revoked user should not be able to write');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        // Ordinary user content read unchanged
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokenA);
        $client->request('GET', '/api/v1/app/contents');
        self::assertResponseStatusCodeSame(200);

        // My authorization endpoint
        $client->request('GET', '/api/v1/app/authorization/me');
        self::assertResponseStatusCodeSame(200);
        $meData = $this->decodeJson($client);
        self::assertArrayHasKey('data', $meData);
        self::assertArrayHasKey('permissions', $meData['data']);
    }

    private function createStore(KernelBrowser $client, string $adminToken, string $code, string $name): ?string
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/stores', [
            'code' => $code,
            'name' => $name,
            'timezone' => 'Asia/Shanghai',
        ]);
        if ($client->getResponse()->getStatusCode() !== 201) {
            $client->request('GET', '/api/v1/manage/stores?limit=100');
            $data = $this->decodeJson($client);
            foreach ($data['data'] ?? [] as $store) {
                if (($store['code'] ?? '') === $code) {
                    return $store['uuid'];
                }
            }
            return null;
        }
        $data = $this->decodeJson($client);
        return $data['data']['uuid'] ?? null;
    }

    private function createStoreContent(KernelBrowser $client, string $token, string $storeUuid, string $title, string $body): ?int
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/contents', $storeUuid), [
            'title' => $title,
            'body' => $body,
        ]);
        if ($client->getResponse()->getStatusCode() !== 201) {
            return null;
        }
        $data = $this->decodeJson($client);
        return $data['data']['id'] ?? null;
    }

    private function grantMembership(KernelBrowser $client, string $adminToken, string $storeUuid, string $userUuid, string $role): void
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->jsonRequest('POST', sprintf('/api/v1/manage/stores/%s/members', $storeUuid), [
            'userUuid' => $userUuid,
            'role' => $role,
        ]);
    }

    private function grantAssignment(KernelBrowser $client, string $adminToken, string $userUuid, string $roleCode, string $scopeType, string $scopeUuid): string
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->request('GET', '/api/v1/manage/roles');
        $data = $this->decodeJson($client);
        $roleUuid = null;
        foreach ($data['data'] ?? [] as $role) {
            if (($role['code'] ?? '') === $roleCode) {
                $roleUuid = $role['uuid'];
                break;
            }
        }
        self::assertNotNull($roleUuid, sprintf('Role %s not found', $roleCode));

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $userUuid,
            'roleUuid' => $roleUuid,
            'scopeType' => $scopeType,
            'scopeUuid' => $scopeUuid,
        ]);
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [200, 201], true), 'Failed to grant assignment: '.$client->getResponse()->getContent());

        return $roleUuid;
    }

    private function createUser(string $email, string $username): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($hasher->hashPassword($user, 'P@ssw0rd'));
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function assertGlobalAssignmentIsUnique(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $role = $em->getRepository(Role::class)->findOneBy(['code' => 'authorization_administrator']);
        self::assertInstanceOf(Role::class, $role);

        $connection = $em->getConnection();
        $values = [
            'role_id' => $role->getId(),
            'user_uuid' => $user->getUuid(),
            'scope_type' => 'global',
            'scope_uuid' => null,
            'scope_key' => '',
            'uuid' => UUID::v4(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
        $connection->insert('authorization_assignment', $values);

        try {
            $connection->insert('authorization_assignment', [...$values, 'uuid' => UUID::v4()]);
            self::fail('Duplicate global assignment was accepted.');
        } catch (UniqueConstraintViolationException) {
            self::addToAssertionCount(1);
        }
    }

    private function findUserByEmail(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        return $user;
    }

    private function loginAndGetToken(string $identifier, string $password = 'P@ssw0rd', ?KernelBrowser $client = null): string
    {
        $owned = $client === null;
        $client ??= static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => $identifier,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        if ($owned) {
            self::ensureKernelShutdown();
        }
        return $data['access_token'];
    }

    private function createAdminAndGetToken(?KernelBrowser $client = null): string
    {
        $owned = $client === null;
        $client ??= static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'testadmin@example.com']);
        if ($admin === null) {
            $admin = new User();
            $admin->setEmail('testadmin@example.com');
            $admin->setUsername('testadmin');
            $admin->setPassword($hasher->hashPassword($admin, 'AdminPass!'));
            $admin->setRoles(['ROLE_ADMIN']);
            $em->persist($admin);
            $em->flush();
        }
        if ($owned) {
            self::ensureKernelShutdown();
        }
        return $this->loginAndGetToken('testadmin@example.com', 'AdminPass!', $owned ? null : $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        return \is_array($decoded) ? $decoded : [];
    }
}
