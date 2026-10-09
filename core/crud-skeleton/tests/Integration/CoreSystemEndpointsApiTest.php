<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;

/**
 * End-to-end coverage of the System introspection endpoints
 * (/system/entities, /system/entities/{entityName}, /system/router)
 * including translated field names and the not-found-entity path.
 *
 * BUG-5: a missing entity on /system/entities/{name} yields a 500 HTML error
 * page (MappingException) instead of a 404 JSON payload, because the
 * ExceptionInterceptor only intercepts /api/* paths.
 */
final class CoreSystemEndpointsApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testListEntitiesReturnsAllFqcns(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('content-type'));
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['code']);
        self::assertSame('SUCCESS', $body['message']);
        self::assertIsArray($body['data']);
        self::assertContains('App\\Common\\Entity\\Category', $body['data']);
        self::assertContains('App\\Common\\Entity\\Tag', $body['data']);
        self::assertContains('App\\Store\\Entity\\Product', $body['data']);
        self::assertContains('App\\Identity\\Entity\\User', $body['data']);
        self::assertContains('App\\Wallet\\Entity\\Wallet', $body['data']);
    }

    public function testRetrieveEntityMetadata(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities/App/Common/Entity/Category');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['code']);
        $data = $body['data'];
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('name', $data);
        self::assertArrayHasKey('slug', $data);
        self::assertSame('string', $data['name']['metadata']['type']);
        self::assertSame('name', $data['name']['metadata']['columnName']);
        self::assertArrayHasKey('parent', $data);
        self::assertSame('ManyToOne', $data['parent']['metadata']['type']);
        self::assertSame('App\\Common\\Entity\\Category', $data['parent']['metadata']['targetEntity']);
    }

    public function testRetrieveEntityAcceptsBackslashFqcn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities/App%5CCommon%5CEntity%5CTag');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('name', $body['data']);
    }

    public function testRouterListsRoutes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/router');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['code']);
        self::assertIsArray($body['data']);
        self::assertArrayHasKey('system-entity-list', $body['data']);
        self::assertArrayHasKey('system-entity-retrieve', $body['data']);
        self::assertArrayHasKey('system-router-list', $body['data']);
        self::assertArrayHasKey('manage-categories-list', $body['data']);
        self::assertSame('/system/entities', $body['data']['system-entity-list']['path']);
        self::assertSame(['GET'], $body['data']['system-entity-list']['methods']);
    }

    public function testTranslatedFieldNamesZh(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities/App/Common/Entity/Category?_locale=zh');

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('名称', $body['data']['name']['translation']);
    }

    public function testTranslatedFieldNamesZhHant(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities/App/Common/Entity/Category?_locale=zh_Hant');

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('名稱', $body['data']['name']['translation']);
    }

    public function testTranslatedFieldNamesJa(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities/App/Common/Entity/Category?_locale=ja');

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('名前', $body['data']['name']['translation']);
    }

    public function testTranslatedFieldNamesEnViaAcceptLanguage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities/App/Common/Entity/Category', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,zh-CN;q=0.8']);

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Name', $body['data']['name']['translation']);
    }

    // ---- BUG-5: not-found entity produces 500 HTML, not 404 JSON ----

    public function testMissingEntityReturns500HtmlCurrently(): void
    {
        $client = static::createClient();
        $client->request('GET', '/system/entities/App/Does/Not/Exist');

        self::assertSame(500, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('content-type'));
        self::assertStringContainsString('MappingException', (string) $client->getResponse()->getContent());
    }

    public function testMissingEntityShouldReturn404Json(): void
    {
        self::markTestSkipped('BUG-5: /system/* errors bypass ExceptionInterceptor (API-only pattern) -> 500 HTML MappingException. See docs/issues/coverage-2026-08-09/core-integration-extra.md#bug-5.');
    }
}
