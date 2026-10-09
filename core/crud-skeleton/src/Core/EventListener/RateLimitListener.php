<?php

declare(strict_types=1);

namespace App\Core\EventListener;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Applies rate limits to public-facing endpoints (login, registration, OTP,
 * WeChat login, payment initiation) keyed by client IP.
 *
 * When a limit is exceeded the controller is replaced with a 429 response in
 * the project's standard envelope {data, code, message} plus a Retry-After
 * header.
 */
final class RateLimitListener
{
    private const LIMITERS = [
        'auth_login' => ['|^/api/auth/login$|'],
        'auth_register' => ['|^/api/auth/register$|'],
        'otp_request' => ['|^/api/auth/otp/request$|'],
        'otp_verify' => ['|^/api/auth/otp/verify$|'],
        'wechat_login' => ['|^/api/wechat/miniapp/login$|', '|^/api/wechat/oauth/callback$|'],
        'payment' => [
            '~^/api/v1/app/orders/.+/payment$~',
            '~^/api/v1/(app|manage)/invoices/.+/pay~',
        ],
    ];

    public function __construct(
        /** @var ContainerInterface $limiters map of limiter-name => RateLimiterFactory */
        private readonly ContainerInterface $limiters,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $limiterName = $this->resolveLimiter($request->getPathInfo());
        if ($limiterName === null) {
            return;
        }

        /** @var RateLimiterFactory $factory */
        $factory = $this->limiters->get($limiterName);
        $limiter = $factory->create($this->key($request));
        $limit = $limiter->consume(1);

        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = $limit->getRetryAfter();
        $headers = [];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) max(1, (int) $retryAfter->getTimestamp() - time());
        }

        $event->setController(function () use ($headers): JsonResponse {
            return new JsonResponse(
                [
                    'data' => null,
                    'code' => Response::HTTP_TOO_MANY_REQUESTS,
                    'message' => $this->translator->trans('Too many requests. Please try again later.'),
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                $headers,
            );
        });
    }

    private function resolveLimiter(string $pathInfo): ?string
    {
        foreach (self::LIMITERS as $name => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $pathInfo) === 1) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function key(Request $request): string
    {
        // Per-client-IP limiting. Behind a reverse proxy, configure trusted
        // proxies so getClientIp() resolves the real client address.
        return (string) $request->getClientIp();
    }
}
