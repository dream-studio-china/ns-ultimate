<?php

namespace App\Tests\Integration;

final class OpenApiIntegrationTest extends IntegrationWebTestCase
{
    public function testSwaggerUiAndJsonEndpointsAreAvailable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('content-type'));

        $client->request('GET', '/api/doc.json');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('content-type'));

        $doc = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('3.1.0', $doc['openapi'] ?? null);
        self::assertArrayHasKey('/api/v1/manage/contents', $doc['paths'] ?? []);
        self::assertArrayHasKey('/api/v1/manage/contents/{id}', $doc['paths'] ?? []);

        self::assertSame(['Wechat'], $doc['paths']['/api/wechat/miniapp/login']['post']['tags'] ?? null);
        self::assertSame(['Wechat'], $doc['paths']['/api/wechat/miniapp/phone']['post']['tags'] ?? null);
        self::assertSame(['Payment'], $doc['paths']['/api/payment/notify/{payment}']['post']['tags'] ?? null);
        self::assertSame(['Media'], $doc['paths']['/api/v1/public/media']['get']['tags'] ?? null);
        self::assertSame(['Media'], $doc['paths']['/api/v1/public/media/{id}']['get']['tags'] ?? null);
        self::assertSame('List public media', $doc['paths']['/api/v1/public/media']['get']['summary'] ?? null);
        self::assertStringContainsString('ownerless media', $doc['paths']['/api/v1/public/media']['get']['description'] ?? '');

        $upload = $doc['paths']['/api/v1/app/media/upload']['post'] ?? [];
        self::assertSame(['Media'], $upload['tags'] ?? null);
        self::assertSame('Upload my media file', $upload['summary'] ?? null);
        self::assertStringContainsString('Authenticated multipart upload endpoint', $upload['description'] ?? '');
        self::assertSame(['file'], $upload['requestBody']['content']['multipart/form-data']['schema']['required'] ?? null);
        self::assertSame('binary', $upload['requestBody']['content']['multipart/form-data']['schema']['properties']['file']['format'] ?? null);
        self::assertSame(['local', 'qiniu'], $upload['requestBody']['content']['multipart/form-data']['schema']['properties']['storage']['enum'] ?? null);
        self::assertSame('integer', $upload['requestBody']['content']['multipart/form-data']['schema']['properties']['category']['type'] ?? null);

        $mediaSchema = $doc['components']['schemas']['Media']['properties'] ?? [];
        self::assertSame(['local', 'qiniu'], $mediaSchema['storage']['enum'] ?? null);
        self::assertSame('#/components/schemas/CategoryRef', $mediaSchema['category']['$ref'] ?? null);
    }
}
