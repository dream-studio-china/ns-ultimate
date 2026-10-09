<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Common;


use PHPUnit\Framework\Attributes\Group;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Symfony\Component\HttpFoundation\Response;

#[Group('low-value')]
final class CommonBatchUpdateTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
    }

    private function json(string $method, string $uri, array $data = []): array
    {
        $this->client->request($method, $uri, [], [], [], json_encode($data, JSON_THROW_ON_ERROR));
        return [$this->client->getResponse(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    public function testContentBatchUpdate(): void
    {
        [, $a] = $this->json('POST', '/api/v1/manage/contents', ['title' => 'CB-A']);
        [, $b] = $this->json('POST', '/api/v1/manage/contents', ['title' => 'CB-B']);

        [$r] = $this->json('POST', '/api/v1/manage/contents/batch-update?@basis=id&@mode=update', [
            ['id' => $a['data']['id'], 'title' => 'CB-A Updated'],
            ['id' => $b['data']['id'], 'title' => 'CB-B Updated'],
        ]);

        self::assertSame(Response::HTTP_OK, $r->getStatusCode());
    }

    public function testCategoryBatchUpdate(): void
    {
        [, $a] = $this->json('POST', '/api/v1/manage/categories', ['name' => 'Cat-A', 'slug' => 'cat-a']);
        [, $b] = $this->json('POST', '/api/v1/manage/categories', ['name' => 'Cat-B', 'slug' => 'cat-b']);

        [$r] = $this->json('POST', '/api/v1/manage/categories/batch-update?@basis=id&@mode=update', [
            ['id' => $a['data']['id'], 'name' => 'Cat-Ax', 'slug' => 'cat-ax'],
            ['id' => $b['data']['id'], 'name' => 'Cat-Bx', 'slug' => 'cat-bx'],
        ]);

        self::assertSame(Response::HTTP_OK, $r->getStatusCode());
    }

    public function testTagBatchUpdate(): void
    {
        [, $a] = $this->json('POST', '/api/v1/manage/tags', ['name' => 'T-A', 'slug' => 't-a']);
        [, $b] = $this->json('POST', '/api/v1/manage/tags', ['name' => 'T-B', 'slug' => 't-b']);

        [$r] = $this->json('POST', '/api/v1/manage/tags/batch-update?@basis=id&@mode=update', [
            ['id' => $a['data']['id'], 'name' => 'T-Ax', 'slug' => 't-ax'],
            ['id' => $b['data']['id'], 'name' => 'T-Bx', 'slug' => 't-bx'],
        ]);

        self::assertSame(Response::HTTP_OK, $r->getStatusCode());
    }

    public function testPageBatchUpdate(): void
    {
        [, $a] = $this->json('POST', '/api/v1/manage/pages', ['title' => 'P-A', 'slug' => 'p-a']);
        [, $b] = $this->json('POST', '/api/v1/manage/pages', ['title' => 'P-B', 'slug' => 'p-b']);

        [$r] = $this->json('POST', '/api/v1/manage/pages/batch-update?@basis=id&@mode=update', [
            ['id' => $a['data']['id'], 'title' => 'P-Ax', 'slug' => 'p-ax'],
            ['id' => $b['data']['id'], 'title' => 'P-Bx', 'slug' => 'p-bx'],
        ]);

        self::assertSame(Response::HTTP_OK, $r->getStatusCode());
    }

    public function testCommentBatchUpdatePartial(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $comment = new \App\Common\Entity\Comment('test', 'content', 1);
        $em->persist($comment);
        $em->flush();

        [$r] = $this->json('POST', '/api/v1/manage/comments/batch-update?@basis=id&@partial=true', [
            ['id' => (int) $comment->getId(), 'body' => 'updated body'],
        ]);

        self::assertSame(Response::HTTP_OK, $r->getStatusCode());
    }

    public function testSettingBatchUpdate(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $s1 = new \App\Common\Entity\Setting('batch.key1');
        $s1->setValue('v1');
        $s2 = new \App\Common\Entity\Setting('batch.key2');
        $s2->setValue('v2');
        $em->persist($s1);
        $em->persist($s2);
        $em->flush();

        [$r] = $this->json('POST', '/api/v1/manage/settings/batch-update?@basis=id&@mode=update', [
            ['id' => (int) $s1->getId(), 'value' => 'updated v1'],
            ['id' => (int) $s2->getId(), 'value' => 'updated v2'],
        ]);

        self::assertSame(Response::HTTP_OK, $r->getStatusCode());
    }

    public function testMediaBatchUpdate(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $m1 = new \App\Common\Entity\Media('a.png', 'a.png', 'image/png', 100, '/tmp/a.png');
        $m2 = new \App\Common\Entity\Media('b.png', 'b.png', 'image/png', 200, '/tmp/b.png');
        $em->persist($m1);
        $em->persist($m2);
        $em->flush();

        [$r] = $this->json('POST', '/api/v1/manage/media/batch-update?@basis=id&@mode=update', [
            ['id' => (int) $m1->getId(), 'originalFilename' => 'updated-a.png'],
            ['id' => (int) $m2->getId(), 'originalFilename' => 'updated-b.png'],
        ]);

        self::assertSame(Response::HTTP_OK, $r->getStatusCode());
    }
}
