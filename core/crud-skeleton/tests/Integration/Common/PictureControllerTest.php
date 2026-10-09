<?php

declare(strict_types=1);

namespace App\Tests\Integration\Common;

use App\Common\Entity\Category;
use App\Common\Entity\Picture;
use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PictureControllerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\\Common\\Entity\\Picture')->execute();
        $this->em->createQuery('DELETE FROM App\\Common\\Entity\\Category')->execute();
        self::ensureKernelShutdown();
    }

    public function testManageCreatesReadsUpdatesAndDeletesPicture(): void
    {
        $client = static::createAuthenticatedClient();
        $category = $this->createCategory('manage-cat');

        $client->request(
            'POST',
            '/api/v1/manage/pictures',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'title' => 'Sunset',
                'category' => $category->getId(),
                'image' => 'https://cdn.example.com/sunset.png',
                'metadata' => ['exif' => ['iso' => 200]],
            ]),
        );

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decode($client->getResponse()->getContent());
        self::assertSame('Sunset', $created['data']['title']);
        self::assertSame('https://cdn.example.com/sunset.png', $created['data']['image']);
        self::assertSame($category->getId(), $created['data']['category']['id']);
        self::assertSame(['exif' => ['iso' => 200]], $created['data']['metadata']);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/pictures');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = $this->decode($client->getResponse()->getContent());
        self::assertContains($id, array_column($list['data'], 'id'));

        $client->request('GET', '/api/v1/manage/pictures/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $detail = $this->decode($client->getResponse()->getContent());
        self::assertSame($id, $detail['data']['id']);

        $client->request(
            'PUT',
            '/api/v1/manage/pictures/' . $id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['title' => 'Sunrise']),
        );
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $updated = $this->decode($client->getResponse()->getContent());
        self::assertSame('Sunrise', $updated['data']['title']);

        $client->request('DELETE', '/api/v1/manage/pictures/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->getRepository(Picture::class)->find($id));
    }

    public function testManageCreateRequiresImageAndCategory(): void
    {
        $client = static::createAuthenticatedClient();
        $category = $this->createCategory('required-cat');

        $client->request(
            'POST',
            '/api/v1/manage/pictures',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['category' => $category->getId()]),
        );
        self::assertSame(400, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $client->request(
            'POST',
            '/api/v1/manage/pictures',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['image' => 'https://cdn.example.com/x.png']),
        );
        self::assertSame(400, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function testManageCreateRejectsUnknownCategory(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/manage/pictures',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'category' => 999999,
                'image' => 'https://cdn.example.com/x.png',
            ]),
        );

        self::assertSame(404, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function testAppCreateAssignsCurrentUserAndIgnoresUserField(): void
    {
        $client = static::createAuthenticatedClient();
        $category = $this->createCategory('app-cat');
        $otherUser = $this->createUser('other-owner@example.com', 'other-owner');

        $client->request(
            'POST',
            '/api/v1/app/pictures',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'title' => 'Mine',
                'category' => $category->getId(),
                'image' => 'https://cdn.example.com/mine.png',
                'user' => $otherUser->getId(),
            ]),
        );

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decode($client->getResponse()->getContent());

        /** @var User $testUser */
        $testUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        /** @var Picture $picture */
        $picture = $this->em->getRepository(Picture::class)->find($created['data']['id']);
        self::assertSame($testUser->getId(), $picture->getUser()?->getId());
    }

    public function testAppOnlySeesAndManagesOwnPictures(): void
    {
        $client = static::createAuthenticatedClient();
        $category = $this->createCategory('scope-cat');

        $otherUser = $this->createUser('scoped-owner@example.com', 'scoped-owner');
        $foreignPicture = new Picture('https://cdn.example.com/foreign.png', $category);
        $foreignPicture->setUser($otherUser);
        $this->em->persist($foreignPicture);
        $this->em->flush();
        $foreignId = $foreignPicture->getId();

        $client->request(
            'POST',
            '/api/v1/app/pictures',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'category' => $category->getId(),
                'image' => 'https://cdn.example.com/own.png',
            ]),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $ownId = $this->decode($client->getResponse()->getContent())['data']['id'];

        $client->request('GET', '/api/v1/app/pictures');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $ids = array_column($this->decode($client->getResponse()->getContent())['data'], 'id');
        self::assertContains($ownId, $ids);
        self::assertNotContains($foreignId, $ids);

        $client->request('GET', '/api/v1/app/pictures/' . $foreignId);
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/app/pictures/' . $foreignId);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->em->getRepository(Picture::class)->find($foreignId));

        $client->request('DELETE', '/api/v1/app/pictures/' . $ownId);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testRepositoryFindByIdAndFindLatest(): void
    {
        $category = $this->createCategory('repo-cat');

        $first = new Picture('https://cdn.example.com/1.png', $category);
        $second = new Picture('https://cdn.example.com/2.png', $category);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        $repo = $this->em->getRepository(Picture::class);

        $found = $repo->findById((int) $second->getId());
        self::assertInstanceOf(Picture::class, $found);
        self::assertSame($second->getId(), $found->getId());

        self::assertNull($repo->findById(999999));

        $latest = $repo->findLatest(1);
        self::assertCount(1, $latest);
        self::assertContainsOnlyInstancesOf(Picture::class, $latest);

        $all = $repo->findLatest();
        self::assertGreaterThanOrEqual(2, count($all));
    }

    private function createCategory(string $prefix): Category
    {
        $category = new Category(ucfirst($prefix), $prefix . '-' . bin2hex(random_bytes(4)));
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function createUser(string $email, string $username): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword('test-password');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array<string, mixed> */
    private function decode(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
