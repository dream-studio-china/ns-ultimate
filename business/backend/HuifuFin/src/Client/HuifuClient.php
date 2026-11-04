<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Client;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HuifuClient implements HuifuClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $sandboxBaseUrl,
        #[Target('monolog.logger.audit')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function post(string $path, array $payload, string $environment = 'production'): array
    {
        $baseUrl = $environment === 'sandbox' ? $this->sandboxBaseUrl : $this->baseUrl;
        if ($baseUrl === '') {
            throw new \RuntimeException(sprintf('The Huifu %s API base URL is not configured.', $environment));
        }
        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        $startedAt = microtime(true);
        $this->audit('info', 'huifu_request', [
            'path' => $path,
            'environment' => $environment,
            'url' => $url,
            'payload' => $payload,
        ]);
        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'timeout' => 10,
                'max_duration' => 20,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            $this->audit('error', 'huifu_request_failed', [
                'path' => $path,
                'environment' => $environment,
                'url' => $url,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);
            throw new \RuntimeException('The Huifu request failed before a response was received.', 0, $exception);
        }

        $this->audit('info', 'huifu_response', [
            'path' => $path,
            'environment' => $environment,
            'httpStatus' => $status,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'body' => $content,
        ]);

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        parse_str($content, $parsed);
        if ($parsed !== []) {
            $result = [];
            foreach ($parsed as $key => $value) {
                $result[(string) $key] = $value;
            }

            return $result;
        }

        throw new \RuntimeException(sprintf('Huifu returned an unreadable response (HTTP %d).', $status));
    }

    /** @param array<string, mixed> $context */
    private function audit(string $level, string $message, array $context): void
    {
        // Provider calls must proceed even when the audit destination is unavailable.
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
        }
    }
}
