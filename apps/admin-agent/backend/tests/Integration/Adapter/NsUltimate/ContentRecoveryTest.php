<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Common\Entity\Content;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ContentRecoveryTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const URL = '/api/v1/manage/agent/operations';

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM admin_agent_operation');
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string { return 'NsUltimate\\Integration\\Backend\\Kernel'; }

    public function testRecoveryListsOnlyMetadataAndRestoresTheOriginalProposalWithoutWriting(): void
    {
        $client = self::createAuthenticatedClient();
        $preview = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'create',
            'changes' => ['title' => 'Private recovery title', 'body' => 'Private recovery body']]);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $count = $em->getRepository(Content::class)->count([]);
        $recent = $this->request($client, 'GET', self::URL);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $recent['items']);
        self::assertSame($preview['operationId'], $recent['items'][0]['operationId']);
        self::assertSame('pending', $recent['items'][0]['status']);
        self::assertArrayHasKey('createdAt', $recent['items'][0]);
        foreach (['Private recovery', 'changes', 'beforeHash', 'actor', 'differences'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, json_encode($recent, JSON_THROW_ON_ERROR));
        }
        $restored = $this->request($client, 'GET', self::URL.'/'.$preview['operationId'].'/preview');
        self::assertSame($preview, $restored);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame($count, $em->getRepository(Content::class)->count([]));
        self::assertSame(1, $em->getRepository(AgentOperation::class)->count([]));
        $receipt = $this->request($client, 'POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertSame('succeeded', $receipt['status']);
        $client->request('GET', self::URL.'/'.$preview['operationId'].'/preview');
        self::assertResponseStatusCodeSame(409);
        $recent = $this->request($client, 'GET', self::URL);
        self::assertSame('succeeded', $recent['items'][0]['status']);
    }

    public function testChangedTargetCannotBeRestoredAndDoesNotCreateAReplacementProposal(): void
    {
        $client = self::createAuthenticatedClient();
        $target = $this->request($client, 'POST', '/api/v1/manage/contents', ['title' => 'Before']);
        $preview = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'update',
            'identifier' => (string) $target['id'], 'changes' => ['title' => 'Proposed']]);
        $this->request($client, 'PUT', '/api/v1/manage/contents/'.$target['id'], ['title' => 'Concurrent edit']);
        $client->request('GET', self::URL.'/'.$preview['operationId'].'/preview');
        self::assertResponseStatusCodeSame(409);
        $failure = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('proposal_conflict', $failure['error']);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Concurrent edit', $em->find(Content::class, $target['id'])->getTitle());
        self::assertSame(1, $em->getRepository(AgentOperation::class)->count([]));
    }

    public function testUnchangedUpdateAndDeleteRestoreFullDiffsAndStillRequireCommit(): void
    {
        $client = self::createAuthenticatedClient();
        $target = $this->request($client, 'POST', '/api/v1/manage/contents', ['title' => 'Before', 'body' => '<p>Existing body</p>']);
        $update = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'update',
            'identifier' => $target['uuid'], 'changes' => ['title' => 'After']]);
        self::assertSame($update, $this->request($client, 'GET', self::URL.'/'.$update['operationId'].'/preview'));
        $this->request($client, 'POST', self::URL.'/'.$update['operationId'].'/commit', ['confirmed' => true]);
        $delete = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'delete', 'identifier' => (string) $target['id']]);
        self::assertSame($delete, $this->request($client, 'GET', self::URL.'/'.$delete['operationId'].'/preview'));
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame('After', $em->find(Content::class, $target['id'])->getTitle());
        $receipt = $this->request($client, 'POST', self::URL.'/'.$delete['operationId'].'/commit', ['confirmed' => true]);
        self::assertSame('succeeded', $receipt['status']);
        self::assertTrue($receipt['verified']);
    }

    public function testExpiryIsReportedBeforePruningAndExpiredOrCancelledProposalsCannotBeRestored(): void
    {
        $client = self::createAuthenticatedClient();
        $preview = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'create', 'changes' => ['title' => 'Expire']]);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $operation = $em->find(AgentOperation::class, $preview['operationId']);
        (new \ReflectionProperty(AgentOperation::class, 'expiresAt'))->setValue($operation, new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $recent = $this->request($client, 'GET', self::URL);
        self::assertSame('expired', $recent['items'][0]['status']);
        $status = $this->request($client, 'GET', self::URL.'/'.$preview['operationId']);
        self::assertSame('expired', $status['status']);
        $client->request('GET', self::URL.'/'.$preview['operationId'].'/preview');
        self::assertResponseStatusCodeSame(409);
        $cancel = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'create', 'changes' => ['title' => 'Cancel']]);
        $this->request($client, 'POST', self::URL.'/'.$cancel['operationId'].'/cancel', []);
        $client->request('GET', self::URL.'/'.$cancel['operationId'].'/preview');
        self::assertResponseStatusCodeSame(409);
    }

    public function testPaginationIsBoundedAndRejectsCallerSuppliedActorOrStatusFilters(): void
    {
        $client = self::createAuthenticatedClient();
        $preview = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'create', 'changes' => ['title' => 'First']]);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $actor = $em->find(AgentOperation::class, $preview['operationId'])->getActor();
        for ($index = 0; $index < 22; ++$index) $em->persist(new AgentOperation($actor, 'Content', 'create', null, str_repeat('a', 64), ['title' => 'Private']));
        $em->persist(new AgentOperation('another-account', 'Content', 'create', null, str_repeat('a', 64), ['title' => 'Hidden']));
        $em->flush();
        $first = $this->request($client, 'GET', self::URL);
        self::assertCount(20, $first['items']);
        self::assertTrue($first['hasMore']);
        $second = $this->request($client, 'GET', self::URL.'?page=2');
        self::assertCount(3, $second['items']);
        self::assertFalse($second['hasMore']);
        self::assertSame([], array_intersect(array_column($first['items'], 'operationId'), array_column($second['items'], 'operationId')));
        $localized = $this->request($client, 'GET', self::URL.'?page=1&_locale=en');
        self::assertSame($first['items'], $localized['items']);
        foreach (['page=0', 'page=101', 'page=x', 'page[]=1', '_locale[]=en', '_locale=', '_locale=en%2FUS', '_locale='.str_repeat('a', 36), 'actor=another-account', 'status=pending'] as $query) {
            $client->request('GET', self::URL.'?'.$query);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testOtherAccountsCannotDiscoverOrRestoreProposalsAndNonAdminsAreDenied(): void
    {
        $client = self::createAuthenticatedClient();
        $preview = $this->request($client, 'POST', self::URL, ['entity' => 'Content', 'action' => 'create', 'changes' => ['title' => 'Private']]);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $other = (new User())->setEmail('recovery-other@example.test')->setUsername('recovery-other')->setRoles(['ROLE_ADMIN']);
        $reader = (new User())->setEmail('recovery-reader@example.test')->setUsername('recovery-reader')->setRoles(['ROLE_USER']);
        $em->persist($other);
        $em->persist($reader);
        $em->flush();
        $tokens = $client->getContainer()->get(TokenManager::class);
        $otherToken = $tokens->createAccessToken($other);
        $readerToken = $tokens->createAccessToken($reader);
        $client->request('GET', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], ($data['data'] ?? $data)['items']);
        $client->request('GET', self::URL.'/'.$preview['operationId'].'/preview', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);
        self::assertResponseStatusCodeSame(404);
        foreach ([self::URL, self::URL.'/'.$preview['operationId'].'/preview'] as $url) {
            $client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$readerToken]);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => '']);
            self::assertResponseStatusCodeSame(401);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(KernelBrowser $client, string $method, string $url, array $payload = []): array
    {
        $client->jsonRequest($method, $url, $payload);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return $data['data'] ?? $data;
    }
}
