<?php

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;

final class CommonModulesApiRegressionTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $tables = [
            'App\\Common\\Entity\\Comment',
            'App\\Common\\Entity\\Setting',
            'App\\Common\\Entity\\Page',
            'App\\Common\\Entity\\Media',
            'App\\Common\\Entity\\Tag',
            'App\\Common\\Entity\\Category',
        ];
        foreach ($tables as $table) {
            $em->createQuery("DELETE FROM $table")->execute();
        }
        self::ensureKernelShutdown();
    }

    // --- Category API Tests ---

    public function testCategoryCrudRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Test Category', 'slug' => 'test-category'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $created['code']);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/categories/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Test Category', $fetched['data']['name']);

        $client->request('PUT', '/api/v1/manage/categories/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Updated Category', 'slug' => 'updated-category'], JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Updated Category', $updated['data']['name']);

        $client->request('GET', '/api/v1/manage/categories?limit=10');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($list['data']);
        self::assertNotEmpty($list['data']);

        $client->request('DELETE', '/api/v1/manage/categories/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/manage/categories/' . $id);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCategoryCreateValidation(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['slug' => 'missing-name'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Name is required', $data['message']);
    }

    public function testCategoryMissingEntity(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('PUT', '/api/v1/manage/categories/999999', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'x', 'slug' => 'x']));
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/manage/categories/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCategoryHierarchyApi(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Parent Cat', 'slug' => 'parent-cat'], JSON_THROW_ON_ERROR));
        $parent = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $parentId = $parent['data']['id'];

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Child Cat', 'slug' => 'child-cat'], JSON_THROW_ON_ERROR));
        $child = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $childId = $child['data']['id'];

        $client->request('PUT', '/api/v1/manage/categories/' . $childId, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Child Cat', 'slug' => 'child-cat', 'parent' => $parentId], JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/manage/categories/' . $childId);
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('parent', $fetched['data']);
        self::assertNotNull($fetched['data']['parent']);

        $client->request('DELETE', '/api/v1/manage/categories/' . $childId);
        $client->request('DELETE', '/api/v1/manage/categories/' . $parentId);
    }

    // --- Tag API Tests ---

    public function testTagCrudRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/tags', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'JavaScript', 'slug' => 'javascript', 'color' => '#f7df1e'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/tags/' . $id);
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('#f7df1e', $fetched['data']['color']);

        $client->request('PUT', '/api/v1/manage/tags/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'JS', 'slug' => 'js', 'color' => '#f0db4f'], JSON_THROW_ON_ERROR));
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('JS', $updated['data']['name']);

        $client->request('GET', '/api/v1/manage/tags?limit=10');
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($list['data']);

        $client->request('DELETE', '/api/v1/manage/tags/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testTagCreateValidation(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/tags', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'missing-slug'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // --- Media API Tests ---

    public function testMediaCrudRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/media', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['filename' => 'test.jpg', 'originalFilename' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'size' => 5000, 'path' => '/uploads/test.jpg'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/media/' . $id);
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/uploads/test.jpg', $fetched['data']['path']);

        $client->request('PUT', '/api/v1/manage/media/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['alt' => 'Alt text', 'title' => 'Image', 'width' => 800, 'height' => 600], JSON_THROW_ON_ERROR));
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Alt text', $updated['data']['alt']);

        $client->request('DELETE', '/api/v1/manage/media/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testMediaCreateValidation(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/media', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['filename' => 'test.jpg'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // --- Page API Tests ---

    public function testPageCrudRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/pages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'body' => 'Privacy content'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/pages/' . $id);
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('draft', $fetched['data']['status']);

        $client->request('PUT', '/api/v1/manage/pages/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'status' => 'published', 'metaTitle' => 'Privacy | Site'], JSON_THROW_ON_ERROR));
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('published', $updated['data']['status']);

        $client->request('DELETE', '/api/v1/manage/pages/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testPageCreateValidation(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/pages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['title' => 'missing-slug'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // --- Comment API Tests ---

    public function testCommentCrudRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['body' => 'Great article!', 'entityType' => 'Content', 'entityId' => 1, 'authorName' => 'Jane', 'authorEmail' => 'jane@test.com'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/comments/' . $id);
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pending', $fetched['data']['status']);

        $client->request('PUT', '/api/v1/manage/comments/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['status' => 'approved', 'body' => 'Great article, thanks!'], JSON_THROW_ON_ERROR));
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('approved', $updated['data']['status']);

        $client->request('DELETE', '/api/v1/manage/comments/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testCommentCreateValidation(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['body' => 'missing required fields'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // --- Setting API Tests ---

    public function testSettingCrudRegression(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/settings', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['key' => 'app_name', 'value' => 'My App', 'type' => 'string', 'groupName' => 'general'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/settings/' . $id);
        $fetched = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('My App', $fetched['data']['value']);

        $client->request('PUT', '/api/v1/manage/settings/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['key' => 'app_name', 'value' => 'Updated App'], JSON_THROW_ON_ERROR));
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Updated App', $updated['data']['value']);

        $client->request('DELETE', '/api/v1/manage/settings/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testSettingCreateValidation(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/settings', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['value' => 'missing-key'], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    // --- Edge cases ---

    public function testMissingEntityAcrossAllModules(): void
    {
        $client = static::createAuthenticatedClient();

        $paths = ['/api/v1/manage/tags/999999', '/api/v1/manage/media/999999', '/api/v1/manage/pages/999999', '/api/v1/manage/comments/999999', '/api/v1/manage/settings/999999'];
        foreach ($paths as $path) {
            $client->request('GET', $path);
            self::assertSame(404, $client->getResponse()->getStatusCode(), "GET $path should return 404");

            $client->request('DELETE', $path);
            self::assertSame(404, $client->getResponse()->getStatusCode(), "DELETE $path should return 404");
        }
    }

    // --- Batch operations ---

    public function testBatchCreateCategories(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            ['name' => 'Cat A', 'slug' => 'cat-a'],
            ['name' => 'Cat B', 'slug' => 'cat-b'],
            ['name' => 'Cat C', 'slug' => 'cat-c'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $response = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($response['data']);
        self::assertCount(3, $response['data']);
    }

    public function testBatchCreateTags(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/tags', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            ['name' => 'Tag X', 'slug' => 'tag-x', 'color' => '#ff0000'],
            ['name' => 'Tag Y', 'slug' => 'tag-y', 'color' => '#00ff00'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $response = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($response['data']);
        self::assertCount(2, $response['data']);
    }
}
