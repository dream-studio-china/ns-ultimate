<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Tests\Integration\Adapter\NsUltimate;

use App\Common\Entity\Content as CoreContent;
use App\Common\Entity\Category;
use App\Common\Entity\Tag;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Command\PruneAgentOperationsCommand;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

final class ContentMutationTest extends IntegrationWebTestCase
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

    public function testPrepareDoesNotWriteAndConfirmedCreateIsIdempotent(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $count = $em->getRepository(CoreContent::class)->count([]);
        $preview = $this->post($client, self::URL, ['action' => 'create', 'changes' => [
            'title' => 'Agent content', 'body' => '<script>untrusted text</script>',
            'metadata' => ['featured' => true, 'layout' => 'article'], 'sortOrder' => 3,
        ]]);
        self::assertFalse($preview['saved']);
        self::assertSame(1, $preview['affectedRecords']);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame($count, $em->getRepository(CoreContent::class)->count([]));

        $url = self::URL.'/'.$preview['operationId'].'/commit';
        $client->jsonRequest('POST', $url, ['confirmed' => true, 'changes' => ['title' => 'Tampered']]);
        self::assertResponseStatusCodeSame(400);
        $receipt = $this->post($client, $url, ['confirmed' => true]);
        self::assertSame('succeeded', $receipt['status']);
        self::assertTrue($receipt['verified']);
        $again = $this->post($client, $url, ['confirmed' => true]);
        self::assertSame($receipt['identifier'], $again['identifier']);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame($count + 1, $em->getRepository(CoreContent::class)->count([]));
        self::assertSame([], $em->find(AgentOperation::class, $preview['operationId'])->getChanges());
    }

    public function testUpdateDeleteAndConflictAreBoundToExactSnapshot(): void
    {
        $client = self::createAuthenticatedClient();
        $created = $this->post($client, '/api/v1/manage/contents', ['title' => 'Original']);
        $input = ['action' => 'update', 'identifier' => (string) $created['id'], 'changes' => ['title' => 'Agent update']];
        $preview = $this->post($client, self::URL, $input);
        $client->jsonRequest('PUT', '/api/v1/manage/contents/'.$created['id'], ['title' => 'Concurrent edit']);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $failure = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('proposal_conflict', $failure['error']);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Concurrent edit', $em->find(CoreContent::class, $created['id'])->getTitle());
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])->getStatus());

        $preview = $this->post($client, self::URL, $input);
        $this->post($client, self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Agent update', $em->find(CoreContent::class, $created['id'])->getTitle());

        $delete = $this->post($client, self::URL, ['action' => 'delete', 'identifier' => $created['uuid']]);
        self::assertSame('delete', $delete['action']);
        self::assertNotEmpty($delete['differences']);
        $receipt = $this->post($client, self::URL.'/'.$delete['operationId'].'/commit', ['confirmed' => true]);
        self::assertTrue($receipt['verified']);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(CoreContent::class, $created['id']));
    }

    public function testDeletedTargetReturnsAnExplicitConflictWithoutExecutingTheProposal(): void
    {
        $client = self::createAuthenticatedClient();
        $target = $this->post($client, '/api/v1/manage/contents', ['title' => 'Delete before commit']);
        $preview = $this->post($client, self::URL, ['action' => 'update', 'identifier' => (string) $target['id'], 'changes' => ['title' => 'Never saved']]);
        $client->request('DELETE', '/api/v1/manage/contents/'.$target['id']);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $failure = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('proposal_conflict', $failure['error']);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(CoreContent::class, $target['id']));
        self::assertSame('pending', $em->find(AgentOperation::class, $preview['operationId'])->getStatus());
        $client->request('GET', self::URL.'/'.$preview['operationId'].'/preview');
        self::assertResponseStatusCodeSame(409);
        $failure = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('proposal_conflict', $failure['error']);
    }

    public function testInvalidFieldsRelationsAndSchemaAreRejected(): void
    {
        $client = self::createAuthenticatedClient();
        foreach ([
            ['title' => 'Bad', 'password' => 'not allowed'], ['title' => ''],
            ['title' => 'Bad', 'metadata' => ['featured' => 'yes']],
            ['title' => 'Bad', 'metadata' => ['secret' => 'not allowed']],
            ['title' => 'Bad', 'category' => 2147483647], ['title' => 'Bad', 'tags' => [2147483647]],
            ['title' => 'Bad', 'sortOrder' => '3'], ['title' => 'Bad', 'body' => str_repeat('x', 20001)],
        ] as $changes) {
            $client->jsonRequest('POST', self::URL, ['entity' => 'Content', 'action' => 'create', 'changes' => $changes]);
            self::assertResponseStatusCodeSame(400);
        }
        $client->jsonRequest('POST', self::URL, ['entity' => 'Category', 'action' => 'create', 'changes' => ['title' => 'No']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAssociationsAreAppliedAndRevalidatedAtCommit(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $category = new Category('Agent category', 'agent-category');
        $tag = new Tag('Agent tag', 'agent-tag');
        $em->persist($category);
        $em->persist($tag);
        $em->flush();
        $categoryId = $category->getId();
        $tagId = $tag->getId();
        $proposal = ['action' => 'create', 'changes' => ['title' => 'Related', 'category' => $categoryId, 'tags' => [$tagId]]];
        $preview = $this->post($client, self::URL, $proposal);
        $receipt = $this->post($client, self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $content = $em->find(CoreContent::class, $receipt['identifier']);
        self::assertSame($categoryId, $content->getCategory()->getId());
        self::assertSame($tagId, $content->getTags()->first()->getId());

        $preview = $this->post($client, self::URL, $proposal);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->remove($em->find(Category::class, $categoryId));
        $em->flush();
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testExpiryCancellationAndAccountBinding(): void
    {
        $client = self::createAuthenticatedClient();
        $preview = $this->post($client, self::URL, ['action' => 'create', 'changes' => ['title' => 'Expire']]);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $operation = $em->find(AgentOperation::class, $preview['operationId']);
        (new \ReflectionProperty(AgentOperation::class, 'expiresAt'))->setValue($operation, new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);
        $client->jsonRequest('GET', self::URL.'/'.$preview['operationId']);
        self::assertResponseIsSuccessful();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $command = new CommandTester($client->getContainer()->get(PruneAgentOperationsCommand::class));
        self::assertSame(0, $command->execute([]));
        $em->clear();
        self::assertSame([], $em->find(AgentOperation::class, $preview['operationId'])->getChanges());
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);

        $cancel = $this->post($client, self::URL, ['action' => 'create', 'changes' => ['title' => 'Cancel']]);
        $this->post($client, self::URL.'/'.$cancel['operationId'].'/cancel');
        $client->jsonRequest('POST', self::URL.'/'.$cancel['operationId'].'/commit', ['confirmed' => true]);
        self::assertResponseStatusCodeSame(409);

        $client->jsonRequest('GET', self::URL.'/'.$cancel['operationId']);
        self::assertResponseIsSuccessful();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $other = (new User())->setEmail('other-content-admin@example.test')->setUsername('other-content-admin')->setRoles(['ROLE_ADMIN']);
        $em->persist($other);
        $em->flush();
        $token = $client->getContainer()->get(TokenManager::class)->createAccessToken($other);
        $client->jsonRequest('GET', self::URL.'/'.$preview['operationId'], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', self::URL.'/'.$preview['operationId'].'/commit', ['confirmed' => true], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthenticatedAndNonAdminCannotPrepareOrCommit(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', self::URL, ['action' => 'create', 'changes' => ['title' => 'Denied']]);
        self::assertResponseStatusCodeSame(401);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('content-agent-reader@example.test')->setUsername('content-agent-reader')->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();
        $token = $client->getContainer()->get(TokenManager::class)->createAccessToken($user);
        foreach ([self::URL, self::URL.'/'.str_repeat('a', 64).'/commit'] as $url) {
            $payload = $url === self::URL ? ['entity' => 'Content', 'action' => 'create', 'changes' => ['title' => 'Denied']] : ['confirmed' => true];
            $client->jsonRequest('POST', $url, $payload, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    private function post(KernelBrowser $client, string $url, array $payload = []): array
    {
        if ($url === self::URL) $payload = ['entity' => 'Content', ...$payload];
        $client->jsonRequest('POST', $url, $payload);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        return $body['data'] ?? $body;
    }
}
