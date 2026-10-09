<?php

declare(strict_types=1);

namespace App\Core\Metrics;

/**
 * Lightweight in-memory Prometheus-style metrics registry.
 *
 * Counters/histograms are per PHP-FPM worker process (documented limitation);
 * DB-backed gauges (outbox backlog, failed messages) are computed live on
 * scrape and therefore accurate across processes.
 *
 * Renders the Prometheus text exposition format (v0.0.4).
 */
final class MetricsRegistry
{
    /** @var array<string, array{help: string, type: string}> */
    private const METADATA = [
        'app_info' => ['help' => 'Application build information', 'type' => 'gauge'],
        'http_requests_total' => ['help' => 'Total HTTP requests handled, by method/route/status', 'type' => 'counter'],
        'http_request_duration_seconds' => ['help' => 'HTTP request duration histogram', 'type' => 'histogram'],
        'http_requests_inflight' => ['help' => 'Current in-flight HTTP requests in this worker', 'type' => 'gauge'],
        'app_outbox_backlog' => ['help' => 'Unpublished outbox rows by topic', 'type' => 'gauge'],
        'app_messenger_failed' => ['help' => 'Messages in the failed transport', 'type' => 'gauge'],
        'metrics_scrape_errors_total' => ['help' => 'Errors while collecting live gauges', 'type' => 'counter'],
    ];

    /** @var array<string, float> counters: "name{labels}" => value */
    private array $counters = [];

    /** @var array<string, float> gauges: "name{labels}" => value */
    private array $gauges = [];

    /** @var array<string, array{buckets: array<float>, counts: array<int>, sum: float, count: int}> histograms */
    private array $histograms = [];

    /** Default histogram buckets in seconds. */
    public const HISTOGRAM_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /**
     * @param array<string, scalar> $labels
     */
    public function incCounter(string $name, array $labels = [], int $by = 1): void
    {
        $key = $this->key($name, $labels);
        $this->counters[$key] = ($this->counters[$key] ?? 0.0) + $by;
    }

    /**
     * @param array<string, scalar> $labels
     */
    public function setGauge(string $name, array $labels = [], float $value = 0.0): void
    {
        $this->gauges[$this->key($name, $labels)] = $value;
    }

    /**
     * @param array<string, scalar> $labels
     */
    public function getGauge(string $name, array $labels = []): float
    {
        return $this->gauges[$this->key($name, $labels)] ?? 0.0;
    }

    /**
     * Record one observation into the named histogram.
     *
     * @param array<float>|null $buckets
     * @param array<string, scalar> $labels
     */
    public function observe(string $name, array $labels = [], float $value = 0.0, ?array $buckets = null): void
    {
        $key = $this->key($name, $labels);
        $buckets ??= self::HISTOGRAM_BUCKETS;
        sort($buckets);

        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = [
                'buckets' => $buckets,
                'counts' => array_fill(0, count($buckets) + 1, 0),
                'sum' => 0.0,
                'count' => 0,
            ];
        }

        $this->histograms[$key]['sum'] += $value;
        $this->histograms[$key]['count']++;
        foreach ($buckets as $i => $bound) {
            if ($value <= $bound) {
                $this->histograms[$key]['counts'][$i]++;
            }
        }
    }

    /**
     * Render all metrics in the Prometheus text exposition format.
     */
    public function render(): string
    {
        $lines = [];
        $lines[] = '# HELP app_info Application build information';
        $lines[] = '# TYPE app_info gauge';
        $lines[] = 'app_info{version="skeleton"} 1';

        $counters = [];
        foreach ($this->counters as $key => $value) {
            $name = $this->nameOf($key);
            if ($name === 'app_info') {
                continue;
            }
            $counters[$name][$key] = $value;
        }

        foreach ($counters as $name => $samples) {
            $meta = self::METADATA[$name] ?? ['help' => $name, 'type' => 'counter'];
            $lines[] = sprintf('# HELP %s %s', $name, $meta['help']);
            $lines[] = sprintf('# TYPE %s counter', $name);
            foreach ($samples as $key => $value) {
                $lines[] = sprintf('%s %s', $key, $this->format($value));
            }
        }

        $gauges = [];
        foreach ($this->gauges as $key => $value) {
            $name = $this->nameOf($key);
            if ($name === 'app_info') {
                continue;
            }
            $gauges[$name][$key] = $value;
        }

        foreach ($gauges as $name => $samples) {
            $meta = self::METADATA[$name] ?? ['help' => $name, 'type' => 'gauge'];
            $lines[] = sprintf('# HELP %s %s', $name, $meta['help']);
            $lines[] = sprintf('# TYPE %s gauge', $name);
            foreach ($samples as $key => $value) {
                $lines[] = sprintf('%s %s', $key, $this->format($value));
            }
        }

        $histograms = [];
        foreach ($this->histograms as $key => $h) {
            $name = $this->nameOf($key);
            $histograms[$name][$key] = $h;
        }

        foreach ($histograms as $name => $samples) {
            $meta = self::METADATA[$name] ?? ['help' => $name, 'type' => 'histogram'];
            $lines[] = sprintf('# HELP %s %s', $name, $meta['help']);
            $lines[] = sprintf('# TYPE %s histogram', $name);
            foreach ($samples as $key => $h) {
                foreach ($h['buckets'] as $i => $bound) {
                    $lines[] = sprintf('%s %d', $this->histogramKey($key, '_bucket', $this->format($bound)), $h['counts'][$i]);
                }
                $lines[] = sprintf('%s %d', $this->histogramKey($key, '_bucket', '+Inf'), $h['count']);
                $lines[] = sprintf('%s %s', $this->histogramKey($key, '_sum'), $this->format($h['sum']));
                $lines[] = sprintf('%s %d', $this->histogramKey($key, '_count'), $h['count']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, scalar> $labels
     */
    private function key(string $name, array $labels): string
    {
        if ($labels === []) {
            return $name;
        }

        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = sprintf('%s="%s"', $k, addcslashes((string) $v, "\\\"\n"));
        }

        return $name . '{' . implode(',', $parts) . '}';
    }

    private function nameOf(string $key): string
    {
        $brace = strpos($key, '{');

        return $brace === false ? $key : substr($key, 0, $brace);
    }

    private function histogramKey(string $key, string $suffix, ?string $bucket = null): string
    {
        $name = $this->nameOf($key);
        $labels = substr($key, strlen($name));
        $sample = $name . $suffix;

        if ($bucket === null) {
            return $sample . $labels;
        }

        $bucketLabel = 'le="' . $bucket . '"';

        return $labels === ''
            ? $sample . '{' . $bucketLabel . '}'
            : $sample . substr($labels, 0, -1) . ',' . $bucketLabel . '}';
    }

    private function format(float $value): string
    {
        if ($value === (float) (int) $value) {
            return number_format($value, 1, '.', '');
        }

        return (string) $value;
    }
}
