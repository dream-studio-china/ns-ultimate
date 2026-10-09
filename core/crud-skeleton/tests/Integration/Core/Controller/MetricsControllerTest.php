<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Controller;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;

/**
 * Prometheus text-format metrics endpoint: public, exposes request counters,
 * duration histogram, live DB-backed outbox/failed-message gauges.
 */
final class MetricsControllerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testMetricsEndpointExposesPrometheusTextFormat(): void
    {
        // Record one main request on a non-skipped path (/system/entities is
        // public and returns 200) so http_requests_total is deterministic.
        // disableReboot() keeps the same kernel/registry across requests —
        // mirroring a long-lived PHP-FPM worker in production.
        $client = static::createClient();
        $client->disableReboot();
        $client->request('GET', '/system/entities');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/metrics');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'text/plain',
            (string) $client->getResponse()->headers->get('content-type'),
        );

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('# TYPE app_info gauge', $content);
        self::assertStringContainsString('app_info{version="skeleton"} 1', $content);

        // Live DB-backed gauges (accurate across workers).
        self::assertStringContainsString('# TYPE app_outbox_backlog gauge', $content);
        self::assertStringContainsString('app_outbox_backlog{topic="trade"}', $content);
        self::assertStringContainsString('app_outbox_backlog{topic="store"}', $content);
        self::assertStringContainsString('app_outbox_backlog{topic="inventory"}', $content);
        self::assertStringContainsString('# TYPE app_messenger_failed gauge', $content);
        self::assertStringContainsString('app_messenger_failed', $content);

        // The /system/entities request above must appear in the counter.
        self::assertStringContainsString('# TYPE http_requests_total counter', $content);
        self::assertStringContainsString('http_requests_total{method="GET",route="system-entity-list",status="200"}', $content);

        // Histogram family is registered by the MetricsListener.
        self::assertStringContainsString('# TYPE http_request_duration_seconds histogram', $content);
        self::assertStringContainsString(
            'http_request_duration_seconds_bucket{route="system-entity-list",le="',
            $content,
        );
    }

    public function testMetricsDoesNotRecordItself(): void
    {
        $client = static::createClient();
        $client->request('GET', '/metrics');
        $content = (string) $client->getResponse()->getContent();

        // The /metrics scrape itself is excluded from http_requests_total.
        self::assertStringNotContainsString('route="metrics-', $content);
        self::assertStringNotContainsString('/metrics', $content);
    }
}
