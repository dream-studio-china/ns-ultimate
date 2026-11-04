<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\EventListener;

use App\Identity\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Persistent API audit log.
 *
 * Production Monolog writes its default handlers to stderr, which depends on
 * PHP-FPM log capture. The project `audit` channel writes rotating JSON lines to
 * `var/log/backend/audit.log` so API calls and provider callbacks remain
 * discoverable. Write requests keep their body; every API request keeps method,
 * path, actor, status and duration.
 *
 * Credential-bearing fields and authentication headers stay excluded because
 * repository policy forbids writing real secrets or tokens to logs. Everything
 * else, including Huifu callback payloads, is recorded verbatim up to the size
 * cap so callback verification failures can be audited.
 */
final class ApiAuditLogListener implements EventSubscriberInterface
{
    private const MAX_BODY_BYTES = 1048576;

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Case-insensitive JSON field names whose values must never reach the log. */
    private const SECRET_KEYS = [
        'password',
        'passwordconfirmation',
        'currentpassword',
        'newpassword',
        'oldpassword',
        'accesstoken',
        'refreshtoken',
        'idtoken',
        'token',
        'otp',
        'otpcode',
        'clientsecret',
        'privatekey',
        'secretkey',
        'merchantprivatekey',
        'apikey',
        'authorization',
    ];

    /** Request headers that carry credentials rather than call metadata. */
    private const SECRET_HEADERS = ['authorization', 'cookie', 'proxy-authorization', 'x-api-key'];

    public function __construct(
        #[Target('monolog.logger.audit')]
        private readonly LoggerInterface $logger,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 1024],
            KernelEvents::EXCEPTION => ['onException', 1024],
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $event->getRequest()->attributes->set('_api_audit_started_at', microtime(true));
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $event->getRequest()->attributes->set('_api_audit_exception', [
            'class' => $event->getThrowable()::class,
            'message' => $event->getThrowable()->getMessage(),
        ]);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $startedAt = $request->attributes->get('_api_audit_started_at');
        if (!is_float($startedAt)) {
            return;
        }
        if (!$this->isApiRequest($request)) {
            return;
        }

        $response = $event->getResponse();
        $method = $request->getMethod();
        $isWrite = in_array($method, self::WRITE_METHODS, true);
        $status = $response->getStatusCode();

        $entry = [
            'method' => $method,
            'path' => $request->getPathInfo(),
            'query' => $request->getQueryString(),
            'route' => $request->attributes->get('_route'),
            'status' => $status,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'user' => $this->resolveUser(),
            'ip' => $request->getClientIp(),
            'contentType' => $request->getContentTypeFormat(),
            'requestId' => $request->headers->get('X-Request-Id'),
            'requestHeaders' => $this->requestHeaders($request),
            'requestBody' => $isWrite ? $this->requestBody($request) : null,
            'responseBody' => $isWrite || $status >= 400 ? $this->responseBody($response) : null,
            'exception' => $request->attributes->get('_api_audit_exception'),
        ];

        // Audit logging must never change the response or fail the request.
        try {
            $this->logger->info('api_request', $entry);
        } catch (\Throwable) {
        }
    }

    private function isApiRequest(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/api') || str_starts_with($path, '/wechat');
    }

    /** @return array<string, string> */
    private function requestHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower((string) $name), self::SECRET_HEADERS, true)) {
                continue;
            }
            $headers[(string) $name] = implode(', ', array_map(strval(...), $values));
        }

        return $headers;
    }

    private function requestBody(Request $request): string
    {
        $content = $request->getContent();
        $length = strlen($content);
        if ($length === 0) {
            return '(empty)';
        }

        return $this->formatBody($content, $length, $request->headers->get('Content-Type'));
    }

    private function responseBody(Response $response): string
    {
        try {
            $content = $response->getContent();
        } catch (\Throwable) {
            return '(unavailable)';
        }
        if (!is_string($content)) {
            return '(streamed)';
        }
        $length = strlen($content);
        if ($length === 0) {
            return '(empty)';
        }

        return $this->formatBody($content, $length, $response->headers->get('Content-Type'));
    }

    private function formatBody(string $content, int $length, ?string $format): string
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            return sprintf('(binary %d bytes)', $length);
        }

        $format = strtolower((string) $format);
        $isJson = str_contains($format, 'json');
        if ($isJson) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $encoded = json_encode(
                    $this->redact($decoded),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
                );
                if (is_string($encoded)) {
                    return $this->truncate($encoded);
                }
            }
        }

        if (str_contains($format, 'x-www-form-urlencoded')) {
            parse_str($content, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                return $this->truncate(http_build_query($this->redact($parsed), '', '&'));
            }
        }

        return $this->truncate($content);
    }

    private function truncate(string $content): string
    {
        if (strlen($content) <= self::MAX_BODY_BYTES) {
            return $content;
        }

        return substr($content, 0, self::MAX_BODY_BYTES).'...[truncated]';
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    private function isSecretKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? $key);

        return in_array($normalized, self::SECRET_KEYS, true);
    }

    private function resolveUser(): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return '(anon)';
        }

        return sprintf('@%s#%d', $user->__toString(), $user->getId() ?? 0);
    }
}
