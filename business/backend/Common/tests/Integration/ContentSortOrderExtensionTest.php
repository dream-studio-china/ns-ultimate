<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Tests\Integration;

use App\Common\Entity\Category;
use App\Common\Entity\Content as CoreContent;
use App\Common\Entity\Tag;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Common\Entity\Content;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ContentSortOrderExtensionTest extends IntegrationWebTestCase
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

    public function testSortOrderUsesExistingContentCrudAndPreservesCoreRows(): void
    {
        $client = self::createAuthenticatedClient();
        $created = $this->request($client, 'POST', '/api/v1/manage/contents', ['title' => 'Later', 'sortOrder' => 25]);
        self::assertSame(25, $created['sortOrder']);
        $second = $this->request($client, 'POST', '/api/v1/manage/contents', ['title' => 'Earlier']);
        self::assertSame(0, $second['sortOrder']);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $loaded = $entityManager->find(CoreContent::class, $created['id']);
        self::assertInstanceOf(Content::class, $loaded);
        self::assertSame(25, $loaded->getSortOrder());

        $this->request($client, 'PUT', '/api/v1/manage/contents/'.$created['id'], ['sortOrder' => 4]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $core = new CoreContent('Core content');
        $entityManager->persist($core);
        $entityManager->flush();
        $coreId = $core->getId();

        foreach (['ASC', 'DESC'] as $direction) {
            foreach (['manage', 'app'] as $scope) {
                $rows = $this->request($client, 'GET', '/api/v1/'.$scope.'/contents?'.http_build_query([
                    '@order' => 'entity.sortOrder|'.$direction,
                ]));
                self::assertContains($coreId, array_column($rows, 'id'));
                $extendedRows = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] !== $coreId));
                self::assertSame($direction === 'ASC' ? [0, 4] : [4, 0], array_column($extendedRows, 'sortOrder'));
            }
        }

        $detail = $this->request($client, 'GET', '/api/v1/manage/contents/'.$coreId);
        self::assertSame('Core content', $detail['title']);
        $updated = $this->request($client, 'PUT', '/api/v1/manage/contents/'.$coreId, ['title' => 'Updated core']);
        self::assertSame('Updated core', $updated['title']);
        $this->request($client, 'DELETE', '/api/v1/manage/contents/'.$created['id']);
    }

    public function testCoreAssociationFieldsRemainWritable(): void
    {
        $client = self::createAuthenticatedClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $category = new Category('Content category', 'content-extension-category');
        $tag = new Tag('Content tag', 'content-extension-tag');
        $entityManager->persist($category);
        $entityManager->persist($tag);
        $entityManager->flush();

        $created = $this->request($client, 'POST', '/api/v1/manage/contents', [
            'title' => 'Related content',
            'category' => $category->getId(),
            'tags' => [$tag->getId()],
            'sortOrder' => 9,
        ]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $loaded = $entityManager->find(Content::class, $created['id']);
        self::assertNotNull($loaded);
        self::assertSame($category->getId(), $loaded->getCategory()?->getId());
        self::assertCount(1, $loaded->getTags());

        $this->request($client, 'PUT', '/api/v1/manage/contents/'.$created['id'], ['category' => null, 'tags' => []]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $loaded = $entityManager->find(Content::class, $created['id']);
        self::assertNotNull($loaded);
        self::assertNull($loaded->getCategory());
        self::assertCount(0, $loaded->getTags());
    }

    public function testSortOrderUpdatesPersistedTimestamp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $content = new Content('Timestamp content');
        $before = new \DateTimeImmutable('2000-01-01T00:00:00+00:00');
        (new \ReflectionProperty(CoreContent::class, 'updatedAt'))->setValue($content, $before);
        $entityManager->persist($content);
        $entityManager->flush();
        $id = $content->getId();
        $entityManager->clear();

        $content = $entityManager->find(Content::class, $id);
        self::assertNotNull($content);
        $content->setSortOrder(7);
        $entityManager->flush();
        $entityManager->clear();
        $loaded = $entityManager->find(Content::class, $id);
        self::assertNotNull($loaded);
        self::assertSame(7, $loaded->getSortOrder());
        self::assertGreaterThan($before, $loaded->getUpdatedAt());
    }

    public function testManagementWritesStillRequireAdmin(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/v1/manage/contents', ['title' => 'Denied', 'sortOrder' => 1]);
        self::assertResponseStatusCodeSame(401);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('content-reader@example.test')->setUsername('content-reader')->setRoles(['ROLE_USER']);
        $entityManager->persist($user);
        $entityManager->flush();
        $token = $client->getContainer()->get(TokenManager::class)->createAccessToken($user);
        $client->jsonRequest('POST', '/api/v1/manage/contents', ['title' => 'Denied', 'sortOrder' => 1], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string|int, mixed> */
    private function request(KernelBrowser $client, string $method, string $path, array $content = []): array
    {
        $client->jsonRequest($method, $path, $content);
        self::assertResponseIsSuccessful();

        if ($client->getResponse()->getStatusCode() === 204) {
            return [];
        }

        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
