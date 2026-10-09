<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Core\Metrics\MetricsRegistry;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Records per-request HTTP metrics (counters, duration histogram, in-flight
 * gauge) into the MetricsRegistry. Skipped for sub-requests and the health,
 * metrics and profiler paths themselves to avoid polluting the data.
 */
final class MetricsListener
{
    private const SKIP_PATTERNS = [
        '|^/health|',
        '|^/metrics|',
        '|^/_profiler|',
        '|^/_wdt|',
        '|^/api/doc|',
    ];

    public function __construct(
        private readonly MetricsRegistry $metrics,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->shouldSkip($request->getPathInfo())) {
            return;
        }

        $request->attributes->set('_metrics_start', microtime(true));
        $this->metrics->setGauge('http_requests_inflight', [], $this->metrics->getGauge('http_requests_inflight') + 1);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $pathInfo = $request->getPathInfo();
        if ($this->shouldSkip($pathInfo)) {
            return;
        }

        $inflight = $this->metrics->getGauge('http_requests_inflight') - 1;
        $this->metrics->setGauge('http_requests_inflight', [], max(0.0, $inflight));

        $start = (float) ($request->attributes->get('_metrics_start') ?? microtime(true));
        $duration = microtime(true) - $start;
        $route = (string) ($request->attributes->get('_route') ?? 'unknown');

        $this->metrics->incCounter('http_requests_total', [
            'method' => $request->getMethod(),
            'route' => $route,
            'status' => (string) $event->getResponse()->getStatusCode(),
        ]);

        $this->metrics->observe('http_request_duration_seconds', ['route' => $route], $duration);
    }

    private function shouldSkip(string $pathInfo): bool
    {
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $pathInfo) === 1) {
                return true;
            }
        }

        return false;
    }
}
