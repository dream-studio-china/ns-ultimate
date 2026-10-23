<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Common\Entity\Tag;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Command\PruneAgentOperationsCommand;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentBatchOperation;
use NsUltimate\Business\Counter\Entity\Counter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

final class BatchAgentOperationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const URL = '/api/v1/manage/agent/batch-operations';

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return 'NsUltimate\\Integration\\Backend\\Kernel';
    }

    public function testTagBatchPreviewCommitReceiptAndIdempotentReplay(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $rows = [
            ['name' => 'Batch Tag One', 'slug' => 'batch-tag-one', 'color' => '#A1b2C3'],
            ['name' => 'Batch Tag Two', 'slug' => 'batch-tag-two', 'color' => null],
        ];

        $preview = $this->prepare($client, 'Tag', $rows);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $preview['operationId']);
        self::assertSame('Tag', $preview['entity']);
        self::assertSame('create', $preview['action']);
        self::assertSame(2, $preview['affectedRecords']);
        self::assertFalse($preview['saved']);
        self::assertSame([1, 2], array_column($preview['rows'], 'row'));
        foreach ($preview['rows'] as $row) {
            self::assertSame(['name', 'slug', 'color'], array_column($row['differences'], 'property'));
        }
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-tag-one']));
        self::assertSame('pending', $em->find(AgentBatchOperation::class, $preview['operationId'])->getStatus());

        $restored = $this->getJson($client, self::URL.'/'.$preview['operationId'].'/preview');
        self::assertSame($preview['operationId'], $restored['operationId']);
        self::assertSame($preview['rows'], $restored['rows']);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-tag-one']));

        $receipt = $this->commit($client, $preview['operationId']);
        self::assertSame('succeeded', $receipt['status']);
        self::assertTrue($receipt['verified']);
        self::assertSame(2, $receipt['rowCount']);
        self::assertSame([1, 2], array_column($receipt['results'], 'row'));
        $firstId = $receipt['results'][0]['identifier'];
        $secondId = $receipt['results'][1]['identifier'];
        self::assertSame('#A1b2C3', $em->find(Tag::class, (int) $firstId)->getColor());
        self::assertNull($em->find(Tag::class, (int) $secondId)->getColor());

        $operation = $em->find(AgentBatchOperation::class, $preview['operationId']);
        self::assertSame([], $operation->getRows());
        self::assertSame('[]', $em->getConnection()->fetchOne('SELECT proposal_rows FROM admin_agent_batch_operation WHERE id = ?', [$preview['operationId']]));
        $receiptText = json_encode($receipt, JSON_THROW_ON_ERROR);
        foreach (['Batch Tag One', 'Batch Tag Two', 'batch-tag-one', 'batch-tag-two'] as $value) {
            self::assertStringNotContainsString($value, $receiptText);
        }

        $again = $this->commit($client, $preview['operationId']);
        self::assertTrue($again['verified']);
        self::assertSame($receipt, $again);
        self::assertSame(1, $em->getRepository(Tag::class)->count(['slug' => 'batch-tag-one']));
        self::assertSame(1, $em->getRepository(Tag::class)->count(['slug' => 'batch-tag-two']));

        $status = $this->getJson($client, self::URL.'/'.$preview['operationId']);
        self::assertSame('succeeded', $status['status']);
        self::assertArrayNotHasKey('verified', $status);
        $recent = $this->getJson($client, self::URL);
        self::assertContains($preview['operationId'], array_column($recent['items'], 'operationId'));
    }

    public function testCounterBatchUsesOneExplicitExistingStoreAndRejectsStaleDependency(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = new Store('batch-counter-store', 'Batch Counter Store');
        $other = new Store('batch-counter-other', 'Other Batch Store');
        $em->persist($store);
        $em->persist($other);
        $em->flush();

        $rows = [
            ['storeUuid' => $store->getUuid(), 'code' => 'BATCH-1', 'name' => 'Batch Counter One'],
            ['storeUuid' => $store->getUuid(), 'code' => 'BATCH-2', 'name' => 'Batch Counter Two', 'capacity' => 4, 'sort' => 2, 'enabled' => true],
        ];
        $preview = $this->prepare($client, 'Counter', $rows);
        self::assertSame(2, $preview['affectedRecords']);
        self::assertSame(['storeUuid', 'code', 'name'], array_column($preview['rows'][0]['differences'], 'property'));
        self::assertSame(0, $em->getRepository(Counter::class)->count(['storeUuid' => $store->getUuid()]));
        $storedRows = $em->find(AgentBatchOperation::class, $preview['operationId'])->getRows();

        $store->suspend();
        $em->flush();
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('proposal_conflict', $this->responseJson($client)['error']);
        self::assertSame(0, $em->getRepository(Counter::class)->count(['storeUuid' => $store->getUuid()]));
        $operation = $em->find(AgentBatchOperation::class, $preview['operationId']);
        self::assertSame('pending', $operation->getStatus());
        self::assertSame($storedRows, $operation->getRows());

        $store->activate();
        $em->flush();
        $otherRows = [
            ['storeUuid' => $store->getUuid(), 'code' => 'BATCH-3', 'name' => 'Mixed Store One'],
            ['storeUuid' => $other->getUuid(), 'code' => 'BATCH-4', 'name' => 'Mixed Store Two'],
        ];
        $this->assertRejected($client, ['entity' => 'Counter', 'action' => 'create', 'rows' => $otherRows], 400);
    }

    public function testSuccessfulCounterBatchCreatesOnlyAfterExplicitConfirmation(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = new Store('batch-counter-success', 'Batch Counter Success');
        $em->persist($store);
        $em->flush();
        $preview = $this->prepare($client, 'Counter', [
            ['storeUuid' => $store->getUuid(), 'code' => 'OK-1', 'name' => 'One'],
            ['storeUuid' => $store->getUuid(), 'code' => 'OK-2', 'name' => 'Two', 'enabled' => false],
        ]);
        self::assertSame(0, $em->getRepository(Counter::class)->count(['storeUuid' => $store->getUuid()]));
        $receipt = $this->commit($client, $preview['operationId']);
        self::assertSame('succeeded', $receipt['status']);
        self::assertSame(2, $em->getRepository(Counter::class)->count(['storeUuid' => $store->getUuid()]));
        self::assertFalse($em->find(Counter::class, (int) $receipt['results'][1]['identifier'])->isEnabled());
        $again = $this->commit($client, $preview['operationId']);
        self::assertTrue($again['verified']);
        self::assertSame($receipt, $again);
        self::assertSame(2, $em->getRepository(Counter::class)->count(['storeUuid' => $store->getUuid()]));
    }

    public function testAnyRowUniquenessConflictRollsBackTheWholeBatch(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $preview = $this->prepare($client, 'Tag', [
            ['name' => 'Rollback One', 'slug' => 'batch-rollback-one'],
            ['name' => 'Rollback Two', 'slug' => 'batch-rollback-two'],
            ['name' => 'Rollback Three', 'slug' => 'batch-rollback-three'],
        ]);

        $em->persist(new Tag('Concurrent Claim', 'batch-rollback-three'));
        $em->flush();
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $failure = $this->responseJson($client);
        self::assertSame('proposal_conflict', $failure['error']);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-rollback-one']));
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-rollback-two']));
        self::assertSame(1, $em->getRepository(Tag::class)->count(['slug' => 'batch-rollback-three']));
        $operation = $em->find(AgentBatchOperation::class, $preview['operationId']);
        self::assertSame('pending', $operation->getStatus());
        self::assertCount(3, $operation->getRows());
        foreach (['Rollback One', 'Rollback Two', 'batch-rollback-one', 'batch-rollback-two'] as $value) {
            self::assertStringNotContainsString($value, json_encode($failure, JSON_THROW_ON_ERROR));
        }
    }

    public function testDirectCommitRejectsStoredRowsChangedWithoutMatchingHash(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $preview = $this->prepare($client, 'Tag', [
            ['name' => 'Commit Guard One', 'slug' => 'batch-commit-guard-one'],
            ['name' => 'Commit Guard Two', 'slug' => 'batch-commit-guard-two'],
        ]);
        $tampered = [
            ['name' => 'Tampered One', 'slug' => 'batch-commit-tampered-one'],
            ['name' => 'Tampered Two', 'slug' => 'batch-commit-tampered-two'],
        ];
        $em->getConnection()->executeStatement(
            'UPDATE admin_agent_batch_operation SET proposal_rows = ? WHERE id = ?',
            [json_encode($tampered, JSON_THROW_ON_ERROR), $preview['operationId']],
        );
        $em->clear();

        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $failure = $this->responseJson($client);
        self::assertSame('proposal_conflict', $failure['error']);
        foreach (['batch-commit-guard-one', 'batch-commit-guard-two', 'batch-commit-tampered-one', 'batch-commit-tampered-two'] as $slug) {
            self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => $slug]));
        }
        $operation = $em->find(AgentBatchOperation::class, $preview['operationId']);
        self::assertSame('pending', $operation->getStatus());
        self::assertSame($tampered, $operation->getRows());
        $serialized = json_encode($failure, JSON_THROW_ON_ERROR);
        foreach (['Commit Guard', 'Tampered', 'batch-commit-guard', 'batch-commit-tampered'] as $value) {
            self::assertStringNotContainsString($value, $serialized);
        }
    }

    public function testDirectCommitRejectsNonCanonicalStoredRowsEvenWhenTheHashMatches(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $store = new Store('batch-canonical-store', 'Batch Canonical Store');
        $em->persist($store);
        $em->flush();
        $preview = $this->prepare($client, 'Counter', [
            ['storeUuid' => $store->getUuid(), 'code' => 'CANON-1', 'name' => 'Canonical One'],
            ['storeUuid' => $store->getUuid(), 'code' => 'CANON-2', 'name' => 'Canonical Two'],
        ]);
        $tampered = [
            ['storeUuid' => $store->getUuid(), 'code' => '  CANON-1  ', 'name' => '  Canonical One  '],
            ['storeUuid' => $store->getUuid(), 'code' => 'CANON-2', 'name' => 'Canonical Two'],
        ];
        $tamperedHash = AgentBatchOperation::hashRows($tampered);
        $em->getConnection()->executeStatement(
            'UPDATE admin_agent_batch_operation SET proposal_rows = ?, rows_hash = ? WHERE id = ?',
            [json_encode($tampered, JSON_THROW_ON_ERROR), $tamperedHash, $preview['operationId']],
        );
        $em->clear();

        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $failure = $this->responseJson($client);
        self::assertSame('proposal_conflict', $failure['error']);
        self::assertSame(0, $em->getRepository(Counter::class)->count(['storeUuid' => $store->getUuid()]));
        self::assertSame('pending', $em->find(AgentBatchOperation::class, $preview['operationId'])->getStatus());
        self::assertStringNotContainsString('CANON-1', json_encode($failure, JSON_THROW_ON_ERROR));
    }

    public function testDuplicatesBoundsAndUnsupportedShapesFailClosed(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Tag('Existing Duplicate', 'batch-existing-duplicate'));
        $em->flush();

        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'create', 'rows' => []], 400);
        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'create', 'rows' => array_fill(0, 21, ['name' => 'Too Many', 'slug' => 'too-many'])], 400);
        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'update', 'rows' => [['name' => 'No']]], 400);
        $this->assertRejected($client, ['entity' => 'Unknown', 'action' => 'create', 'rows' => [['name' => 'No']]], 400);
        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'create', 'rows' => [['name' => 'No']], 'extra' => true], 400);
        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'create', 'rows' => ['not-an-object']], 400);
        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'create', 'rows' => [
            ['name' => 'Duplicate One', 'slug' => 'batch-inside-duplicate'],
            ['name' => 'Duplicate Two', 'slug' => 'batch-inside-duplicate'],
        ]], 400);
        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'create', 'rows' => [
            ['name' => 'Case One', 'slug' => 'batch-case-duplicate'],
            ['name' => 'Case Two', 'slug' => 'BATCH-CASE-DUPLICATE'],
        ]], 400);
        $this->assertRejected($client, ['entity' => 'Tag', 'action' => 'create', 'rows' => [
            ['name' => 'Existing Duplicate', 'slug' => 'batch-existing-duplicate'],
        ]], 400);

        $store = new Store('batch-counter-duplicates', 'Batch Counter Duplicates');
        $em->persist($store);
        $em->flush();
        $this->assertRejected($client, ['entity' => 'Counter', 'action' => 'create', 'rows' => [
            ['storeUuid' => $store->getUuid(), 'code' => 'DUP', 'name' => 'Duplicate One'],
            ['storeUuid' => $store->getUuid(), 'code' => 'DUP', 'name' => 'Duplicate Two'],
        ]], 400);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-inside-duplicate']));
        self::assertSame(0, $em->getRepository(Counter::class)->count(['storeUuid' => $store->getUuid()]));
    }

    public function testMaximumBatchSizeIsAccepted(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['name' => 'Maximum '.$i, 'slug' => 'batch-maximum-'.$i];
        }
        $preview = $this->prepare($client, 'Tag', $rows);
        self::assertSame(20, $preview['affectedRecords']);
        $receipt = $this->commit($client, $preview['operationId']);
        self::assertSame('succeeded', $receipt['status']);
        self::assertCount(20, $receipt['results']);
        self::assertSame(20, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM common_tag WHERE slug LIKE 'batch-maximum-%'"));
    }

    public function testActorIsolationAuthorizationAndRevocationRollBackWithoutWrites(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $preview = $this->prepare($client, 'Tag', [['name' => 'Actor Isolated', 'slug' => 'batch-actor-isolated']]);

        $other = (new User())->setEmail('batch-other-admin@example.test')->setUsername('batch-other-admin')->setRoles(['ROLE_ADMIN']);
        $em->persist($other);
        $em->flush();
        $otherToken = $client->getContainer()->get(TokenManager::class)->createAccessToken($other);
        foreach ([
            ['GET', self::URL.'/'.$preview['operationId'], null],
            ['GET', self::URL.'/'.$preview['operationId'].'/preview', null],
            ['POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]],
            ['POST', self::URL.'/'.$preview['operationId'].'/cancel', []],
        ] as [$method, $url, $payload]) {
            if ($method === 'GET') {
                $client->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);
            } else {
                $client->jsonRequest($method, $url, $payload, ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);
            }
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);
        self::assertResponseIsSuccessful();
        self::assertNotContains($preview['operationId'], array_column($this->responseJson($client)['items'], 'operationId'));

        $reader = (new User())->setEmail('batch-reader@example.test')->setUsername('batch-reader')->setRoles(['ROLE_USER']);
        $em->persist($reader);
        $em->flush();
        $readerToken = $client->getContainer()->get(TokenManager::class)->createAccessToken($reader);
        $client->jsonRequest('POST', self::URL, ['entity' => 'Tag', 'action' => 'create', 'rows' => [['name' => 'Denied', 'slug' => 'batch-denied']]], ['HTTP_AUTHORIZATION' => 'Bearer '.$readerToken]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-denied']));

        self::ensureKernelShutdown();
        $revokedClient = self::createClient();
        $em = $revokedClient->getContainer()->get(EntityManagerInterface::class);
        $actor = (new User())->setEmail('batch-revoked-admin@example.test')->setUsername('batch-revoked-admin')->setRoles(['ROLE_ADMIN']);
        $em->persist($actor);
        $em->flush();
        $token = $revokedClient->getContainer()->get(TokenManager::class)->createAccessToken($actor);
        $revokedClient->jsonRequest('POST', self::URL, ['entity' => 'Tag', 'action' => 'create', 'rows' => [
            ['name' => 'Revoked One', 'slug' => 'batch-revoked-one'],
            ['name' => 'Revoked Two', 'slug' => 'batch-revoked-two'],
        ]], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $revokedPreview = $this->responseJson($revokedClient);
        $actor->setRoles(['ROLE_USER']);
        $em->flush();
        $revokedClient->jsonRequest('POST', self::URL.'/'.$revokedPreview['operationId'].'/commit', ['confirmed' => true], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(403);
        $em = $revokedClient->getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-revoked-one']));
        self::assertSame('pending', $em->find(AgentBatchOperation::class, $revokedPreview['operationId'])->getStatus());
    }

    public function testCancelErasesValuesAndLeavesNoWrites(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $preview = $this->prepare($client, 'Tag', [
            ['name' => 'Cancel Secret One', 'slug' => 'batch-cancel-secret-one'],
            ['name' => 'Cancel Secret Two', 'slug' => 'batch-cancel-secret-two'],
        ]);
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/cancel');
        self::assertResponseIsSuccessful();
        $receipt = $this->responseJson($client);
        self::assertSame('cancelled', $receipt['status']);
        self::assertSame([], $receipt['results']);
        self::assertSame('[]', $em->getConnection()->fetchOne('SELECT proposal_rows FROM admin_agent_batch_operation WHERE id = ?', [$preview['operationId']]));
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-cancel-secret-one']));
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $serialized = json_encode([$receipt, $this->responseJson($client)], JSON_THROW_ON_ERROR);
        foreach (['Cancel Secret One', 'Cancel Secret Two', 'batch-cancel-secret-one', 'batch-cancel-secret-two'] as $value) {
            self::assertStringNotContainsString($value, $serialized);
        }
    }

    public function testRestoreToleratesStoredMapOrderButRejectsChangedCanonicalRowsAndExpiry(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $preview = $this->prepare($client, 'Tag', [['name' => 'Restore Tag', 'slug' => 'batch-restore-tag', 'color' => '#112233']]);
        $stored = json_decode((string) $em->getConnection()->fetchOne('SELECT proposal_rows FROM admin_agent_batch_operation WHERE id = ?', [$preview['operationId']]), true, flags: JSON_THROW_ON_ERROR);
        $reversed = array_map(static fn (array $row): array => array_reverse($row, true), $stored);
        $em->getConnection()->executeStatement('UPDATE admin_agent_batch_operation SET proposal_rows = ? WHERE id = ?', [json_encode($reversed, JSON_THROW_ON_ERROR), $preview['operationId']]);
        $em->clear();
        $restored = $this->getJson($client, self::URL.'/'.$preview['operationId'].'/preview');
        self::assertSame($preview['rows'], $restored['rows']);

        $reversed[0]['slug'] = 'batch-restore-changed';
        $em->getConnection()->executeStatement('UPDATE admin_agent_batch_operation SET proposal_rows = ? WHERE id = ?', [json_encode($reversed, JSON_THROW_ON_ERROR), $preview['operationId']]);
        $em->clear();
        $client->request('GET', self::URL.'/'.$preview['operationId'].'/preview');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('proposal_conflict', $this->responseJson($client)['error']);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-restore-tag']));

        $expiring = $this->prepare($client, 'Tag', [['name' => 'Expired Tag', 'slug' => 'batch-expired-tag']]);
        $em->getConnection()->executeStatement('UPDATE admin_agent_batch_operation SET expires_at = ? WHERE id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $expiring['operationId']]);
        $em->clear();
        $status = $this->getJson($client, self::URL.'/'.$expiring['operationId']);
        self::assertSame('expired', $status['status']);
        $command = new CommandTester($client->getContainer()->get(PruneAgentOperationsCommand::class));
        self::assertSame(0, $command->execute([]));
        $em->clear();
        $pruned = $em->find(AgentBatchOperation::class, $expiring['operationId']);
        self::assertSame([], $pruned->getRows());
        self::assertSame('expired', $pruned->getStatus());
        $client->jsonRequest('POST', self::URL.'/'.$expiring['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $em->getRepository(Tag::class)->count(['slug' => 'batch-expired-tag']));
    }

    private function prepare(KernelBrowser $client, string $entity, array $rows): array
    {
        $client->jsonRequest('POST', self::URL, ['entity' => $entity, 'action' => 'create', 'rows' => $rows]);
        self::assertResponseIsSuccessful();

        return $this->responseJson($client);
    }

    private function commit(KernelBrowser $client, string $operationId): array
    {
        $client->jsonRequest('POST', self::URL.'/'.$operationId.'/commit', ['confirmed' => true]);
        self::assertResponseIsSuccessful();

        return $this->responseJson($client);
    }

    private function getJson(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->responseJson($client);
    }

    private function assertRejected(KernelBrowser $client, array $payload, int $status): void
    {
        $client->jsonRequest('POST', self::URL, $payload);
        self::assertResponseStatusCodeSame($status);
    }

    private function responseJson(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $body['data'] ?? $body;
    }
}
