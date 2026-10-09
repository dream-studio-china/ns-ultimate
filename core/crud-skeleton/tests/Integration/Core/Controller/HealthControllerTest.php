<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Controller;

use App\Core\Controller\HealthController;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

/**
 * Container / load-balancer probes. Public endpoints: they must answer
 * without any JWT token.
 */
#[AllowMockObjectsWithoutExpectations]
final class HealthControllerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testLiveReturnsOkWithoutAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
    }

    public function testReadyReportsOkWhenDatabaseIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/ready');

        // Test env disables the Redis probe (see services.yaml when@test), so
        // readiness depends only on the database.
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertSame('ok', $body['checks']['database']);
        self::assertSame('disabled', $body['checks']['redis']);
    }

    public function testReadyReturns503WhenDatabaseIsDown(): void
    {
        // Deterministic unit-level probe: the Connection throws -> degraded 503.
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('connection refused'));

        $controller = new HealthController($connection, '', new NullLogger());
        $response = $controller->ready();

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('degraded', $body['status']);
        self::assertSame('error', $body['checks']['database']);
        // The internal exception message must not leak to anonymous callers.
        self::assertStringNotContainsString('connection refused', (string) $response->getContent());
    }

    public function testReadyDoesNotExposeRedisConnectionErrors(): void
    {
        $client = static::createClient();
        $connection = $client->getContainer()->get(Connection::class);
        $controller = new HealthController($connection, 'redis://127.0.0.1:1', new NullLogger());

        $response = $controller->ready();

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $body['checks']['redis']);
        self::assertStringNotContainsString('connection failed', (string) $response->getContent());
    }
}
