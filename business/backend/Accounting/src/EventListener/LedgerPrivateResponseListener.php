<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Successful Finance responses are per-user and must never be cached by shared
 * caches. Marking every `business_accounting_*` route here keeps individual
 * controllers and the Core REST reply helpers free of transport concerns.
 */
#[AsEventListener]
final class LedgerPrivateResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!str_starts_with((string) $event->getRequest()->attributes->get('_route'), 'business_accounting_')) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary('Authorization');
    }
}
