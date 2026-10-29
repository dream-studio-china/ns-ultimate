<?php

declare(strict_types=1);

namespace NsUltimate\Integration\Backend\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reject oversized password-hashing batches before the core controller runs.
 *
 * User creation hashes each password, so a very large JSON array can exceed the
 * PHP request time limit. In development the fatal error is returned as HTTP 200
 * HTML while the transaction rolls back, which clients can mistake for success.
 * A bounded batch fails fast with a JSON 400 instead.
 */
final class AdminUserBatchLimitListener implements EventSubscriberInterface
{
    public const MAX_USERS = 40;

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ($request->getMethod() !== Request::METHOD_POST || $request->getPathInfo() !== '/api/v1/manage/users') {
            return;
        }
        $content = json_decode($request->getContent(), true);
        if (!is_array($content) || !array_is_list($content) || count($content) <= self::MAX_USERS) {
            return;
        }
        $event->setResponse(new JsonResponse([
            'code' => 400,
            'message' => sprintf('At most %d users can be created per request.', self::MAX_USERS),
            'raw_data' => '',
        ], 400));
    }
}
