<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\EventListener;

use NsUltimate\Business\Accounting\Service\LedgerError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener(priority: 32)]
final class LedgerViewErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if (!$error instanceof LedgerError || !str_starts_with((string) $event->getRequest()->attributes->get('_route'), 'business_accounting_')) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['code' => $error->status, 'message' => $error->getMessage()],
            $error->status,
            ['Cache-Control' => 'private, no-store', 'Vary' => 'Authorization'],
        ));
    }
}
