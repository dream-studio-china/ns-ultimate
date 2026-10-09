<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;

/**
 * End-to-end coverage of the Core ExceptionInterceptor and the
 * success/warning response envelopes through the HTTP kernel.
 *
 * Covers:
 *  - uncaught exception on an /api route -> translated JSON error envelope
 *  - 404 route -> 404 JSON error envelope
 *  - @showDQL outside dev -> 403 JSON error envelope
 *  - success envelope shape ({data,code,message,paginator})
 *  - warning envelope shape ({code,message,raw_data})
 */
final class CoreExceptionInterceptorApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testUncaughtExceptionReturnsJsonEnvelope(): void
    {
        $client = static::createAuthenticatedClient();
        // @select with an unknown field raises an uncaught Doctrine QueryException (500).
        $client->request('GET', '/api/v1/manage/categories?@select=entity.doesNotExistField');

        $response = $client->getResponse();
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(500, $body['code']);
        self::assertArrayHasKey('message', $body);
        self::assertSame('Doctrine\\ORM\\Query\\QueryException', $body['class']);
        self::assertStringContainsString('doesNotExistField', $body['message']);
    }

    public function testNotFoundRouteReturnsJsonEnvelope(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/definitely-not-a-route');

        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(404, $body['code']);
        self::assertArrayHasKey('message', $body);
        self::assertSame('Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', $body['class']);
    }

    public function testAccessDeniedOnShowDqlReturnsJsonEnvelope(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?@showDQL=1');

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(403, $body['code']);
        self::assertArrayHasKey('class', $body);
        self::assertSame('Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException', $body['class']);
    }

    public function testInvalidJsonReturnsWarningEnvelope(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/v1/manage/categories', server: ['CONTENT_TYPE' => 'application/json'], content: '{bad json');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('code', $body);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('raw_data', $body);
        self::assertArrayNotHasKey('data', $body);
        self::assertSame(400, $body['code']);
        self::assertSame('Invalid JSON', $body['message']);
    }

    public function testSuccessListEnvelopeShape(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories?limit=5');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('code', $body);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('paginator', $body);
        self::assertSame(0, $body['code']);
        self::assertSame('SUCCESS', $body['message']);
        self::assertIsArray($body['data']);
    }

    public function testSuccessDetailEnvelopeHasNoPaginator(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/contents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['title' => 'env-title', 'body' => 'env-body'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/contents/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('code', $body);
        self::assertArrayHasKey('message', $body);
        self::assertArrayNotHasKey('paginator', $body);
        self::assertSame(0, $body['code']);
    }

    public function testWarning404EnvelopeShape(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/manage/categories/999999');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('code', $body);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('raw_data', $body);
        self::assertArrayNotHasKey('data', $body);
        self::assertSame(404, $body['code']);
        self::assertSame('Entity is not found', $body['message']);
    }
}
