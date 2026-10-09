<?php

declare(strict_types=1);

namespace App\Core\Controller;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Container/load-balancer probes. Public (no JWT): these endpoints live
 * outside the /api firewall so orchestrators can poll them without tokens.
 *
 * - /health/live  — process liveness (always 200 while PHP serves requests)
 * - /health/ready — readiness: database required, Redis optional (enabled only
 *                   when OTP_REDIS_DSN is configured)
 */
#[Route('/health', name: 'health-')]
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $otpRedisDsn,
        private readonly LoggerInterface $logger,
    ) {}

    #[OA\Get(
        path: '/health/live',
        summary: 'Liveness probe',
        responses: [
            new OA\Response(response: 200, description: 'Process is alive'),
        ],
        tags: ['System'],
    )]
    #[Route('/live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[OA\Get(
        path: '/health/ready',
        summary: 'Readiness probe (database + optional Redis)',
        responses: [
            new OA\Response(response: 200, description: 'Ready to serve traffic'),
            new OA\Response(response: 503, description: 'A required dependency is unavailable'),
        ],
        tags: ['System'],
    )]
    #[Route('/ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $checks = [];

        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            // Never leak driver details to anonymous callers; log them instead.
            $this->logger->error('Health check: database probe failed', ['exception' => $e]);
            $checks['database'] = 'error';
        }

        $checks['redis'] = $this->checkRedis();

        $ready = $checks['database'] === 'ok' && in_array($checks['redis'], ['ok', 'disabled'], true);

        return new JsonResponse(
            ['status' => $ready ? 'ok' : 'degraded', 'checks' => $checks],
            $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * Dependency-free Redis probe: PING over TCP (RESP). Returns 'disabled'
     * when no DSN is configured, 'ok' on +PONG, otherwise an error string.
     */
    private function checkRedis(): string
    {
        $dsn = trim($this->otpRedisDsn);
        if ($dsn === '') {
            return 'disabled';
        }

        $parts = parse_url($dsn);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int) ($parts['port'] ?? 6379);
        if ($host === '') {
            return 'disabled';
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($socket === false) {
            $this->logger->error('Health check: Redis probe failed', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
            ]);

            return 'error';
        }

        try {
            fwrite($socket, "PING\r\n");
            $reply = fgets($socket, 64);
            if ($reply !== false && str_starts_with($reply, '+PONG')) {
                return 'ok';
            }

            $this->logger->error('Health check: Redis probe returned an unexpected reply');

            return 'error';
        } finally {
            fclose($socket);
        }
    }
}
