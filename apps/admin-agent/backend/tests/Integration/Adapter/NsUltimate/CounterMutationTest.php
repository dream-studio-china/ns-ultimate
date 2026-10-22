<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Business\Counter\Entity\Counter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CounterMutationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const URL = '/api/v1/manage/agent/operations';

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testNameUpdateUsesExactTargetAndProvidesSafeIdempotentReceipt(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('counter-a')->setName('Original');
        $em->persist($counter);
        $em->flush();
        $id = $counter->getId();
        $uuid = $counter->getUuid();

        $preview = $this->post($client, ['action' => 'update', 'identifier' => $uuid, 'changes' => ['name' => '  Updated  ']]);
        self::assertSame((string) $id, $preview['identifier']);
        self::assertSame([['property' => 'name', 'before' => 'Original', 'after' => 'Updated']], $preview['differences']);
        self::assertSame('Original', $em->find(Counter::class, $id)->getName());

        $receipt = $this->post($client, ['operation' => $preview['operationId'], 'confirmed' => true]);
        self::assertSame('succeeded', $receipt['status']);
        self::assertTrue($receipt['verified']);
        self::assertSame((string) $id, $receipt['identifier']);
        self::assertArrayNotHasKey('name', $receipt);
        $again = $this->post($client, ['operation' => $preview['operationId'], 'confirmed' => true]);
        self::assertSame($receipt['identifier'], $again['identifier']);
        self::assertSame('Updated', $em->find(Counter::class, $id)->getName());
        self::assertSame([], $em->find(AgentOperation::class, $preview['operationId'])->getChanges());
    }

    public function testUnsupportedActionsFieldsInvalidAndNoOpAreRejectedWithoutWrites(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('counter-b')->setName('Stable');
        $em->persist($counter);
        $em->flush();
        foreach ([
            ['action' => 'create', 'changes' => ['name' => 'New']],
            ['action' => 'delete', 'identifier' => (string) $counter->getId()],
            ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => 'Different', 'enabled' => false]],
            ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['code' => 'different']],
            ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => '']],
            ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => null]],
            ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => ['array']]],
            ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => str_repeat('x', 121)]],
            ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => 'Stable']],
            ['action' => 'update', 'identifier' => '1garbage', 'changes' => ['name' => 'Different']],
        ] as $proposal) {
            $client->jsonRequest('POST', self::URL, ['entity' => 'Counter', ...$proposal]);
            self::assertResponseStatusCodeSame(400);
        }
        self::assertSame('Stable', $em->find(Counter::class, $counter->getId())->getName());
    }

    public function testNameBoundaryAndExactIdentifierForms(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('boundary')->setName('Original');
        $em->persist($counter);
        $em->flush();
        foreach ([(string) $counter->getId(), $counter->getUuid()] as $identifier) {
            $client->jsonRequest('POST', self::URL, ['entity' => 'Counter', 'action' => 'update', 'identifier' => $identifier,
                'changes' => ['name' => str_repeat('é', 120)]]);
            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(str_repeat('é', 120), ($body['data'] ?? $body)['differences'][0]['after']);
        }
    }

    public function testAuthorizationIsRecheckedForEveryOperationRoute(): void
    {
        $admin = self::createAuthenticatedClient();
        $em = $admin->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('counter-c')->setName('Protected');
        $em->persist($counter);
        $em->flush();
        $preview = $this->post($admin, ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => 'Changed']]);
        $user = (new User())->setEmail('counter-agent-reader@example.test')->setUsername('counter-agent-reader')->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();
        $token = $admin->getContainer()->get(TokenManager::class)->createAccessToken($user);
        foreach ([
            ['GET', self::URL.'/'.$preview['operationId']],
            ['GET', self::URL.'/'.$preview['operationId'].'/preview'],
            ['POST', self::URL.'/'.$preview['operationId'].'/commit'],
            ['POST', self::URL.'/'.$preview['operationId'].'/cancel'],
        ] as [$method, $url]) {
            $admin->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(403);
        }
        $sameActor = $em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $sameActor);
        $sameActor->setRoles(['ROLE_USER']);
        $em->flush();
        $revokedToken = $admin->getContainer()->get(TokenManager::class)->createAccessToken($sameActor);
        $admin->request('GET', self::URL.'/'.$preview['operationId'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$revokedToken]);
        self::assertResponseStatusCodeSame(403);
        $sameActor->setRoles(['ROLE_ADMIN']);
        $em->flush();
        self::assertSame('Protected', $em->find(Counter::class, $counter->getId())->getName());
    }

    public function testOriginalActorRoleRevokedBetweenPrepareAndCommitCannotWrite(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $actor = (new User())->setEmail('counter-revoked-actor@example.test')->setUsername('counter-revoked-actor')->setRoles(['ROLE_ADMIN']);
        $em->persist($actor);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('revoked-actor')->setName('Protected');
        $em->persist($counter);
        $em->flush();
        $token = $client->getContainer()->get(TokenManager::class)->createAccessToken($actor);
        $client->jsonRequest('POST', self::URL, ['entity' => 'Counter', 'action' => 'update', 'identifier' => (string) $counter->getId(),
            'changes' => ['name' => 'Must not apply']], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $preview = $body['data'] ?? $body;
        $actor->setRoles(['ROLE_USER']);
        $em->flush();

        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Protected', $em->find(Counter::class, $counter->getId())->getName());
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])->getStatus());
    }

    public function testStaleSnapshotConflictsWithoutChangingTheCounter(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('counter-d')->setName('Before');
        $em->persist($counter);
        $em->flush();
        $id = $counter->getId();
        $preview = $this->post($client, ['action' => 'update', 'identifier' => (string) $id, 'changes' => ['name' => 'Proposed']]);

        $counter->setName('Concurrent');
        $em->flush();
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $failure = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('proposal_conflict', $failure['error']);
        self::assertSame('Concurrent', $em->find(Counter::class, $id)->getName());
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])->getStatus());
    }

    public function testNonNameSnapshotChangesConflict(): void
    {
        foreach ([
            static fn (Counter $target) => $target->setCapacity(8),
            static fn (Counter $target) => $target->setEnabled(false),
            static fn (Counter $target) => $target->setStoreUuid('00000000-0000-4000-8000-000000000002'),
            static fn (Counter $target) => $target->setCode('concurrent-code'),
            static fn (Counter $target) => $target->setMetadata(['concurrent' => true]),
        ] as $index => $change) {
            $client = self::createAuthenticatedClient();
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('snapshot-'.$index)->setName('Before');
            $em->persist($counter);
            $em->flush();
            $preview = $this->post($client, ['action' => 'update', 'identifier' => (string) $counter->getId(), 'changes' => ['name' => 'Proposed']]);
            $change($counter);
            $em->flush();
            $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])->getStatus());
            self::ensureKernelShutdown();
        }
    }

    public function testRawSqlNonNameChangeConflictsWithManagedProposal(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('raw-stale')->setName('Before');
        $em->persist($counter);
        $em->flush();
        $id = $counter->getId();
        $preview = $this->post($client, ['action' => 'update', 'identifier' => (string) $id, 'changes' => ['name' => 'Proposed']]);

        $em->getConnection()->executeStatement('UPDATE counter SET capacity = ? WHERE id = ?', [37, $id]);
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Before', $em->find(Counter::class, $id)->getName());
        self::assertSame(37, $em->getConnection()->fetchOne('SELECT capacity FROM counter WHERE id = ?', [$id]));
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])->getStatus());
    }

    public function testDeletedTargetAllowsCancelButRestoreAndCommitConflict(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('sentinel-code')->setName('Sentinel Name');
        $em->persist($counter);
        $em->flush();
        $id = $counter->getId();
        $cancel = $this->post($client, ['action' => 'update', 'identifier' => (string) $id, 'changes' => ['name' => 'Proposed Secret']]);
        $restore = $this->post($client, ['action' => 'update', 'identifier' => (string) $id, 'changes' => ['name' => 'Other Proposed Secret']]);
        $commit = $this->post($client, ['action' => 'update', 'identifier' => (string) $id, 'changes' => ['name' => 'Commit Secret']]);
        $em->remove($em->find(Counter::class, $id));
        $em->flush();
        $em->clear();

        $client->jsonRequest('POST', self::URL.'/'.$cancel['operationId'].'/cancel');
        self::assertResponseIsSuccessful();
        $cancelled = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('cancelled', ($cancelled['data'] ?? $cancelled)['status']);
        self::assertSame([], $em->find(AgentOperation::class, $cancel['operationId'])->getChanges());
        foreach ([self::URL.'/'.$restore['operationId'].'/preview', self::URL.'/'.$commit['operationId'].'/commit'] as $url) {
            $method = str_ends_with($url, '/commit') ? 'POST' : 'GET';
            if ($method === 'POST') $client->jsonRequest('POST', $url, ['confirmed' => true]);
            else $client->request('GET', $url);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('proposal_conflict', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']);
        }
        $serialized = json_encode([$cancelled, $client->getResponse()->getContent()], JSON_THROW_ON_ERROR);
        foreach (['sentinel-code', 'Sentinel Name', 'Proposed Secret', 'Other Proposed Secret', 'Commit Secret'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function testDeletedTargetAfterSuccessStillAllowsStatusReplayAndRecentRecovery(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('receipt-code')->setName('Receipt Name');
        $em->persist($counter);
        $em->flush();
        $id = $counter->getId();
        $preview = $this->post($client, ['action' => 'update', 'identifier' => (string) $id, 'changes' => ['name' => 'Receipt Proposed']]);
        $receipt = $this->post($client, ['operation' => $preview['operationId'], 'confirmed' => true]);
        $em->remove($em->find(Counter::class, $id));
        $em->flush();
        $em->clear();

        $client->request('GET', self::URL.'/'.$preview['operationId']);
        self::assertResponseIsSuccessful();
        $statusBody = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $status = $statusBody['data'] ?? $statusBody;
        self::assertSame('succeeded', $status['status']);
        $again = $this->post($client, ['operation' => $preview['operationId'], 'confirmed' => true]);
        $client->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        $recentBody = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $recent = ($recentBody['data'] ?? $recentBody)['items'];
        self::assertSame($receipt['identifier'], $again['identifier']);
        self::assertContains($preview['operationId'], array_column($recent, 'operationId'));
        foreach ([$status, $again, $recent] as $result) {
            $text = json_encode($result, JSON_THROW_ON_ERROR);
            foreach (['receipt-code', 'Receipt Name', 'Receipt Proposed', '00000000-0000-4000-8000-000000000001'] as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
        }
        self::assertSame([], $em->find(AgentOperation::class, $preview['operationId'])->getChanges());
    }

    private function post(KernelBrowser $client, array $payload): array
    {
        if (isset($payload['operation'])) {
            $url = self::URL.'/'.$payload['operation'].'/commit';
            unset($payload['operation']);
        } else {
            $url = self::URL;
            $payload = ['entity' => 'Counter', ...$payload];
        }
        $client->jsonRequest('POST', $url, $payload);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return $body['data'] ?? $body;
    }
}
