<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Tests\Integration;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;

final class OrderPricingErrorTest extends IntegrationWebTestCase
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

    public function testUnknownSpecificationIsAClientError(): void
    {
        $client = self::createAuthenticatedClient();
        $client->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => 99999999, 'quantity' => 1]],
        ]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, $body['code']);
        self::assertStringContainsString('not found or not available', $body['message']);
    }
}
