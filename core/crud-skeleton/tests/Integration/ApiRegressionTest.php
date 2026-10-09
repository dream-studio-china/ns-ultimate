<?php

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;

final class ApiRegressionTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\\Common\\Entity\\Content c')->execute();
        self::ensureKernelShutdown();
    }

    public function testContentCrudRegressionFlow(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/manage/contents',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['title' => 'regression-title', 'body' => 'regression-body'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $created['code']);
        self::assertIsArray($created['data']);
        self::assertIsInt($created['data']['id']);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/contents/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('regression-title', $fetched['data']['title']);

        $client->request(
            'PUT',
            '/api/v1/manage/contents/' . $id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['title' => 'updated-title', 'body' => 'updated-body'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated-title', $updated['data']['title']);

        $client->request('GET', '/api/v1/manage/contents?limit=10');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertArrayHasKey('data', $list);
        self::assertIsArray($list['data']);
        self::assertNotEmpty($list['data']);

        $client->request('DELETE', '/api/v1/manage/contents/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/manage/contents/' . $id);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCreateValidationRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/manage/contents',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'missing-title'], JSON_THROW_ON_ERROR)
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Title is required', $data['message']);
    }

    public function testUpdateAndDeleteMissingEntityRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'PUT',
            '/api/v1/manage/contents/999999',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['title' => 'x'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/manage/contents/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}
