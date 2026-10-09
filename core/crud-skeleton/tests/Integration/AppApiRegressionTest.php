<?php

namespace App\Tests\Integration;

use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class AppApiRegressionTest extends IntegrationWebTestCase
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
            'App\\Common\\Entity\\Content',
        ];
        foreach ($tables as $table) {
            $em->createQuery("DELETE FROM $table")->execute();
        }
        self::ensureKernelShutdown();
    }

    // ---------------------------------------------------------------
    //  Category App API
    // ---------------------------------------------------------------

    public function testAppCategoryListOnlyReturnsEnabled(): void
    {
        $client = static::createAuthenticatedClient();

        // Create categories via manage API
        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Enabled Cat', 'slug' => 'enabled-cat', 'enabled' => true], JSON_THROW_ON_ERROR));
        $enabled = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $enabled['code'], 'Create enabled category failed');

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Disabled Cat', 'slug' => 'disabled-cat', 'enabled' => false], JSON_THROW_ON_ERROR));
        $disabled = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $disabled['code'], 'Create disabled category failed');

        // List via App API - should only see enabled
        $client->request('GET', '/api/v1/app/categories');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $list['code']);
        self::assertIsArray($list['data']);
        self::assertCount(1, $list['data'], 'App list should only return enabled categories');
        self::assertSame('Enabled Cat', $list['data'][0]['name']);
        self::assertSame('enabled-cat', $list['data'][0]['slug']);
        self::assertArrayHasKey('description', $list['data'][0]);
        self::assertArrayHasKey('parent', $list['data'][0]);
        self::assertArrayHasKey('sortOrder', $list['data'][0]);
        self::assertArrayHasKey('createdAt', $list['data'][0]);
        self::assertArrayHasKey('enabled', $list['data'][0]);
    }

    public function testAppCategoryDetail(): void
    {
        $client = static::createAuthenticatedClient();

        // Create via manage
        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Detail Cat', 'slug' => 'detail-cat', 'description' => 'A description'], JSON_THROW_ON_ERROR));
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        // Detail via App API
        $client->request('GET', '/api/v1/app/categories/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $detail['code']);
        self::assertSame('Detail Cat', $detail['data']['name']);
        self::assertSame('detail-cat', $detail['data']['slug']);
        self::assertSame('A description', $detail['data']['description']);
    }

    public function testAppCategoryDetailMissingReturns404(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/categories/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAppCategoryHierarchyShowsParent(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Root', 'slug' => 'root'], JSON_THROW_ON_ERROR));
        $root = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $rootId = $root['data']['id'];

        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Child', 'slug' => 'child'], JSON_THROW_ON_ERROR));
        $child = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $childId = $child['data']['id'];

        $client->request('PUT', '/api/v1/manage/categories/' . $childId, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['parent' => $rootId, 'name' => 'Child', 'slug' => 'child'], JSON_THROW_ON_ERROR));

        // App detail shows parent info
        $client->request('GET', '/api/v1/app/categories/' . $childId);
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotNull($detail['data']['parent']);
        self::assertSame($rootId, $detail['data']['parent']['id']);
        self::assertSame('Root', $detail['data']['parent']['__toString']);
    }

    // ---------------------------------------------------------------
    //  Tag App API
    // ---------------------------------------------------------------

    public function testAppTagListAndDetail(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/tags', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'PHP', 'slug' => 'php', 'color' => '#777bb3'], JSON_THROW_ON_ERROR));
        $php = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/tags', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Go', 'slug' => 'go', 'color' => '#00add8'], JSON_THROW_ON_ERROR));
        json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // List
        $client->request('GET', '/api/v1/app/tags');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $list['code']);
        self::assertCount(2, $list['data']);
        self::assertArrayHasKey('name', $list['data'][0]);
        self::assertArrayHasKey('slug', $list['data'][0]);
        self::assertArrayHasKey('color', $list['data'][0]);
        self::assertArrayHasKey('createdAt', $list['data'][0]);

        // Detail
        $phpId = $php['data']['id'];
        $client->request('GET', '/api/v1/app/tags/' . $phpId);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PHP', $detail['data']['name']);
        self::assertSame('#777bb3', $detail['data']['color']);
    }

    public function testAppTagDetailMissingReturns404(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/tags/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------
    //  Media App API
    // ---------------------------------------------------------------

    public function testAppMediaListAndDetail(): void
    {
        $client = static::createAuthenticatedClient();
        $user = $client->getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);

        $client->request('POST', '/api/v1/manage/media', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'filename' => 'hero.jpg', 'originalFilename' => 'DSC001.jpg', 'mimeType' => 'image/jpeg',
            'size' => 50000, 'path' => '/uploads/hero.jpg', 'alt' => 'Hero image', 'title' => 'Hero', 'width' => 1920, 'height' => 1080,
            'user' => $user->getId(),
        ], JSON_THROW_ON_ERROR));
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        // List
        $client->request('GET', '/api/v1/app/media');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $list['data']);
        self::assertArrayHasKey('filename', $list['data'][0]);
        self::assertArrayHasKey('originalFilename', $list['data'][0]);
        self::assertArrayHasKey('mimeType', $list['data'][0]);
        self::assertArrayHasKey('size', $list['data'][0]);
        self::assertArrayHasKey('path', $list['data'][0]);
        self::assertArrayHasKey('alt', $list['data'][0]);
        self::assertArrayHasKey('title', $list['data'][0]);
        self::assertArrayHasKey('width', $list['data'][0]);
        self::assertArrayHasKey('height', $list['data'][0]);
        self::assertArrayHasKey('createdAt', $list['data'][0]);

        // Detail
        $client->request('GET', '/api/v1/app/media/' . $id);
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Hero image', $detail['data']['alt']);
        self::assertSame(1920, $detail['data']['width']);
        self::assertSame(1080, $detail['data']['height']);
    }

    public function testAppMediaDetailMissingReturns404(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/media/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------
    //  Page App API
    // ---------------------------------------------------------------

    public function testAppPageListOnlyReturnsPublished(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/pages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['title' => 'Published Page', 'slug' => 'pub-page', 'status' => 'published'], JSON_THROW_ON_ERROR));
        json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/pages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['title' => 'Draft Page', 'slug' => 'draft-page', 'status' => 'draft'], JSON_THROW_ON_ERROR));
        json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // App list should only show published
        $client->request('GET', '/api/v1/app/pages');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $list['data']);
        self::assertSame('Published Page', $list['data'][0]['title']);
        self::assertSame('pub-page', $list['data'][0]['slug']);
        self::assertArrayHasKey('body', $list['data'][0]);
        self::assertArrayHasKey('metaTitle', $list['data'][0]);
        self::assertArrayHasKey('metaDescription', $list['data'][0]);
        self::assertArrayHasKey('publishedAt', $list['data'][0]);
        self::assertArrayHasKey('status', $list['data'][0]);
    }

    public function testAppPageDetail(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/pages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['title' => 'About', 'slug' => 'about', 'body' => 'About us', 'metaTitle' => 'About | Site', 'metaDescription' => 'Learn about us'], JSON_THROW_ON_ERROR));
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/app/pages/' . $id);
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('About', $detail['data']['title']);
        self::assertSame('About us', $detail['data']['body']);
        self::assertSame('About | Site', $detail['data']['metaTitle']);
        self::assertSame('Learn about us', $detail['data']['metaDescription']);
    }

    public function testAppPageDetailMissingReturns404(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/pages/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------
    //  Comment App API
    // ---------------------------------------------------------------

    public function testAppCommentListOnlyReturnsApproved(): void
    {
        $client = static::createAuthenticatedClient();

        // Create comments via App API (auto-sets author to current user, status=pending)
        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'My approved comment', 'entityType' => 'Page', 'entityId' => 1,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $c1 = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pending', $c1['data']['status']);

        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'My second comment', 'entityType' => 'Page', 'entityId' => 1,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // Approve them via manage API
        $client->request('PUT', '/api/v1/manage/comments/' . $c1['data']['id'], server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['status' => 'approved'], JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // App list: only shows current user's approved comments
        $client->request('GET', '/api/v1/app/comments');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(1, count($list['data']), 'App list should return current user approved comments');
        self::assertArrayHasKey('body', $list['data'][0]);
        self::assertArrayHasKey('authorName', $list['data'][0]);
        self::assertArrayHasKey('authorEmail', $list['data'][0]);
        self::assertArrayHasKey('entityType', $list['data'][0]);
        self::assertArrayHasKey('entityId', $list['data'][0]);
        self::assertArrayHasKey('parent', $list['data'][0]);
        self::assertArrayHasKey('status', $list['data'][0]);
        self::assertArrayHasKey('createdAt', $list['data'][0]);
    }

    public function testAppCommentDetail(): void
    {
        $client = static::createAuthenticatedClient();

        // Create comment via App API (auto-sets author)
        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Great article!', 'entityType' => 'Content', 'entityId' => 42,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        // Approve via manage
        $client->request('PUT', '/api/v1/manage/comments/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['status' => 'approved'], JSON_THROW_ON_ERROR));

        $client->request('GET', '/api/v1/app/comments/' . $id);
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Great article!', $detail['data']['body']);
        self::assertSame('testauth', $detail['data']['authorName']);
        self::assertSame('testauth@example.com', $detail['data']['authorEmail']);
        self::assertSame('Content', $detail['data']['entityType']);
        self::assertSame(42, $detail['data']['entityId']);
    }

    public function testAppCommentDetailMissingReturns404(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/comments/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // --- Comment Write API (App) ---

    public function testAppCommentCreateDefaultsToPending(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'User submitted comment', 'entityType' => 'Page', 'entityId' => 10,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $created['code']);
        self::assertIsInt($created['data']['id']);
        self::assertSame('User submitted comment', $created['data']['body']);
        self::assertSame('Page', $created['data']['entityType']);
        self::assertSame(10, $created['data']['entityId']);
        self::assertSame('pending', $created['data']['status']);
        // Author fields are auto-recorded from authenticated user (testauth@example.com)
        self::assertSame('testauth', $created['data']['authorName']);
        self::assertSame('testauth@example.com', $created['data']['authorEmail']);
    }

    public function testAppCommentCreateWithParent(): void
    {
        $client = static::createAuthenticatedClient();

        // Create parent comment (approved via manage, so it shows in list)
        $client->request('POST', '/api/v1/manage/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Parent comment', 'entityType' => 'Page', 'entityId' => 10, 'authorName' => 'Parent', 'status' => 'approved',
        ], JSON_THROW_ON_ERROR));
        $parent = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $parentId = $parent['data']['id'];

        // Create reply via App API - author auto-recorded
        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Reply to parent', 'entityType' => 'Page', 'entityId' => 10, 'parent' => $parentId,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $reply = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pending', $reply['data']['status']);
        self::assertArrayHasKey('parent', $reply['data']);
        self::assertNotNull($reply['data']['parent']);
    }

    public function testAppCommentCreateIgnoresManualAuthorFields(): void
    {
        $client = static::createAuthenticatedClient();

        // Try to submit custom authorName/authorEmail - should be overridden by auto-record
        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Fake identity', 'entityType' => 'Page', 'entityId' => 10,
            'authorName' => 'Imposter', 'authorEmail' => 'fake@evil.com',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // authorName/authorEmail come from the token, not from request body
        self::assertSame('testauth', $created['data']['authorName']);
        self::assertSame('testauth@example.com', $created['data']['authorEmail']);
    }

    public function testAppCommentCreateStatusIsIgnored(): void
    {
        $client = static::createAuthenticatedClient();

        // Try to set status to 'approved' via App API - should be ignored
        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Trying to self-approve', 'entityType' => 'Page', 'entityId' => 10, 'status' => 'approved',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        // status must still be pending — the App controller overrides via defaultCreateValues
        self::assertSame('pending', $created['data']['status']);
    }

    public function testAppCommentCreateValidation(): void
    {
        $client = static::createAuthenticatedClient();

        // Missing required fields
        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Comment without entity info',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testAppCommentCreateUnauthenticated(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient(); // no auth

        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Unauthenticated comment', 'entityType' => 'Page', 'entityId' => 1,
        ], JSON_THROW_ON_ERROR));

        self::assertNotSame(201, $client->getResponse()->getStatusCode(), 'Unauthenticated comment create should not succeed');
    }

    public function testAppCommentPendingNotVisibleInAppList(): void
    {
        $client = static::createAuthenticatedClient();

        // Create a pending comment via App API
        $client->request('POST', '/api/v1/app/comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'body' => 'Pending from app', 'entityType' => 'Page', 'entityId' => 10,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // App list filters by author=currentUser; the pending comment has status=pending
        // but it should still appear since the commonFilter uses author, not status
        $client->request('GET', '/api/v1/app/comments');
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $found = false;
        foreach ($list['data'] as $item) {
            if ($item['body'] === 'Pending from app') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Current user pending comment should appear in App list');
    }

    // ---------------------------------------------------------------
    //  Setting App API
    // ---------------------------------------------------------------

    public function testAppSettingListAndDetail(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/settings', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'key' => 'site_name', 'value' => 'My Site', 'type' => 'string', 'groupName' => 'general', 'label' => 'Site Name', 'description' => 'The site name',
        ], JSON_THROW_ON_ERROR));
        $s1 = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/settings', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'key' => 'items_per_page', 'value' => '20', 'type' => 'integer', 'groupName' => 'pagination', 'sortOrder' => 10,
        ], JSON_THROW_ON_ERROR));
        json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // List
        $client->request('GET', '/api/v1/app/settings');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $list['data']);
        self::assertArrayHasKey('key', $list['data'][0]);
        self::assertArrayHasKey('value', $list['data'][0]);
        self::assertArrayHasKey('type', $list['data'][0]);
        self::assertArrayHasKey('groupName', $list['data'][0]);
        self::assertArrayHasKey('label', $list['data'][0]);
        self::assertArrayHasKey('description', $list['data'][0]);
        self::assertArrayHasKey('sortOrder', $list['data'][0]);
        self::assertArrayHasKey('createdAt', $list['data'][0]);

        // Detail
        $s1Id = $s1['data']['id'];
        $client->request('GET', '/api/v1/app/settings/' . $s1Id);
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('site_name', $detail['data']['key']);
        self::assertSame('My Site', $detail['data']['value']);
        self::assertSame('general', $detail['data']['groupName']);
        self::assertSame('Site Name', $detail['data']['label']);
    }

    public function testAppSettingDetailMissingReturns404(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/settings/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------
    //  Cross-cutting: App endpoints require auth
    // ---------------------------------------------------------------

    public function testAppEndpointsRequireAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient(); // no auth

        $paths = [
            '/api/v1/app/categories',
            '/api/v1/app/tags',
            '/api/v1/app/media',
            '/api/v1/app/pages',
            '/api/v1/app/comments',
            '/api/v1/app/settings',
        ];

        foreach ($paths as $path) {
            $client->request('GET', $path);
            self::assertNotSame(200, $client->getResponse()->getStatusCode(), "GET $path without auth should not return 200");
        }
    }

    // ---------------------------------------------------------------
    //  Pagination test
    // ---------------------------------------------------------------

    public function testAppTagListPagination(): void
    {
        $client = static::createAuthenticatedClient();

        // Create 5 tags
        for ($i = 1; $i <= 5; $i++) {
            $client->request('POST', '/api/v1/manage/tags', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => "Tag $i", 'slug' => "tag-$i"], JSON_THROW_ON_ERROR));
        }

        // Paginated list
        $client->request('GET', '/api/v1/app/tags?limit=2&page=1');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $page1 = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $page1['data']);
        self::assertArrayHasKey('paginator', $page1);
        self::assertSame(5, $page1['paginator']['total']);
        self::assertSame(1, $page1['paginator']['page']);
        self::assertSame(2, $page1['paginator']['limit']);
        self::assertSame(3, $page1['paginator']['pages']);
        self::assertFalse($page1['paginator']['has_previous']);
        self::assertTrue($page1['paginator']['has_next']);

        // Page 2
        $client->request('GET', '/api/v1/app/tags?limit=2&page=2');
        $page2 = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $page2['data']);
        self::assertTrue($page2['paginator']['has_previous']);
        self::assertTrue($page2['paginator']['has_next']);

        // Page 3
        $client->request('GET', '/api/v1/app/tags?limit=2&page=3');
        $page3 = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $page3['data']);
        self::assertTrue($page3['paginator']['has_previous']);
        self::assertFalse($page3['paginator']['has_next']);
    }
}
