<?php

declare(strict_types=1);

namespace App\Core\Controller;

use App\Core\Metrics\MetricsRegistry;
use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prometheus text-format metrics endpoint (public).
 *
 * In-memory request metrics (counters, duration histogram, in-flight gauge)
 * are per PHP-FPM worker. DB-backed gauges (outbox backlog, failed messenger
 * messages) are computed live on each scrape and are therefore accurate
 * across all workers.
 */
#[Route('/metrics', name: 'metrics-')]
final class MetricsController
{
    public function __construct(
        private readonly MetricsRegistry $metrics,
        private readonly Connection $connection,
    ) {}

    #[OA\Get(
        path: '/metrics',
        summary: 'Prometheus metrics in text exposition format',
        responses: [
            new OA\Response(response: 200, description: 'Metrics'),
        ],
        tags: ['System'],
    )]
    #[Route('', methods: ['GET'])]
    public function index(): Response
    {
        $this->collectLiveGauges();

        return new Response(
            $this->metrics->render(),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }

    /**
     * Query DB-backed gauges that must reflect all workers/processes.
     */
    private function collectLiveGauges(): void
    {
        $tables = [
            'trade' => 'trade_outbox_message',
            'store' => 'store_outbox_message',
            'inventory' => 'inventory_outbox_message',
        ];

        foreach ($tables as $topic => $table) {
            $this->metrics->setGauge('app_outbox_backlog', ['topic' => $topic], $this->countRows(
                sprintf('SELECT COUNT(*) FROM %s WHERE published_at IS NULL', $table),
            ));
        }

        $this->metrics->setGauge('app_messenger_failed', [], $this->countRows(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'",
        ));
    }

    private function countRows(string $sql): float
    {
        try {
            return (float) $this->connection->executeQuery($sql)->fetchOne();
        } catch (\Throwable $e) {
            $this->metrics->incCounter('metrics_scrape_errors_total', ['error' => get_class($e)]);

            return 0.0;
        }
    }
}
