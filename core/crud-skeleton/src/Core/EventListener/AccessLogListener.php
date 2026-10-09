<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use App\Identity\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class AccessLogListener
{
    private const MAX_BODY_LENGTH = 4096;
    private const AUTH_PATH_PATTERNS = [
        '|^/api/auth|',
        '|^/api/wechat|',
    ];

    public function __construct(
        #[Target('monolog.logger.access')]
        private readonly LoggerInterface $logger,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $method = $request->getMethod();

        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            return;
        }

        $response = $event->getResponse();
        $pathInfo = $request->getPathInfo();
        $isAuthPath = $this->isAuthPath($pathInfo);

        $requestBody = '(body hidden)';
        $responseBody = '(body hidden)';

        if (!$isAuthPath) {
            $requestBody = $request->getContent() ?: '(empty)';
            if (strlen($requestBody) > self::MAX_BODY_LENGTH) {
                $requestBody = substr($requestBody, 0, self::MAX_BODY_LENGTH) . '...[truncated]';
            }

            $responseBody = $response->getContent();
            if ($responseBody === false) {
                $responseBody = '(binary)';
            } elseif ($responseBody === '') {
                $responseBody = '(empty)';
            } elseif (strlen($responseBody) > self::MAX_BODY_LENGTH) {
                $responseBody = substr($responseBody, 0, self::MAX_BODY_LENGTH) . '...[truncated]';
            }
        }

        $this->logger->info(sprintf(
            '%s %s %s | %d | REQ: %s | RES: %s',
            $this->resolveUser(),
            $method,
            $request->getRequestUri(),
            $response->getStatusCode(),
            $requestBody,
            $responseBody,
        ));
    }

    private function resolveUser(): string
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return '(anon)';
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return '(anon)';
        }

        $id = $user->getId();

        return sprintf('@%s#%d', $user->__toString(), $id ?? 0);
    }

    private function isAuthPath(string $pathInfo): bool
    {
        foreach (self::AUTH_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $pathInfo) === 1) {
                return true;
            }
        }

        return false;
    }
}
