<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Metrics;

use App\Core\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;

final class MetricsRegistryTest extends TestCase
{
    public function testHistogramSuffixesPrecedeLabels(): void
    {
        $metrics = new MetricsRegistry();
        $metrics->observe('http_request_duration_seconds', ['route' => 'demo'], 0.01, [0.01]);

        $rendered = $metrics->render();

        self::assertStringContainsString('http_request_duration_seconds_bucket{route="demo",le="0.01"} 1', $rendered);
        self::assertStringContainsString('http_request_duration_seconds_bucket{route="demo",le="+Inf"} 1', $rendered);
        self::assertStringContainsString('http_request_duration_seconds_sum{route="demo"} 0.01', $rendered);
        self::assertStringContainsString('http_request_duration_seconds_count{route="demo"} 1', $rendered);
        self::assertStringNotContainsString('http_request_duration_seconds{route="demo"}_bucket', $rendered);
    }

    public function testMetricsMetadataIsDeclaredOncePerMetricFamily(): void
    {
        $metrics = new MetricsRegistry();
        $metrics->setGauge('app_outbox_backlog', ['topic' => 'trade'], 1);
        $metrics->setGauge('app_outbox_backlog', ['topic' => 'store'], 2);
        $metrics->observe('http_request_duration_seconds', ['route' => 'first'], 0.01, [0.01]);
        $metrics->observe('http_request_duration_seconds', ['route' => 'second'], 0.01, [0.01]);

        $rendered = $metrics->render();

        self::assertSame(1, substr_count($rendered, '# HELP app_outbox_backlog '));
        self::assertSame(1, substr_count($rendered, '# TYPE app_outbox_backlog gauge'));
        self::assertSame(1, substr_count($rendered, '# HELP http_request_duration_seconds '));
        self::assertSame(1, substr_count($rendered, '# TYPE http_request_duration_seconds histogram'));
        self::assertStringContainsString('app_outbox_backlog{topic="trade"} 1.0', $rendered);
        self::assertStringContainsString('app_outbox_backlog{topic="store"} 2.0', $rendered);
    }
}
