<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\EventListener;

use NsUltimate\Business\Accounting\Service\LedgerError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders every Accounting {@see LedgerError} as the single Finance error shape,
 * so controllers never catch it themselves. The body mirrors the Core REST
 * `warning()` envelope (`code`, translated `message`, `raw_data`); the private
 * cache headers are added for all Finance routes by {@see LedgerPrivateResponseListener}.
 */
#[AsEventListener(priority: 32)]
final class LedgerViewErrorListener
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if (!$error instanceof LedgerError
            || !str_starts_with((string) $event->getRequest()->attributes->get('_route'), 'business_accounting_')) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['code' => -1, 'message' => $this->translator->trans($error->getMessage()), 'raw_data' => ''],
            $error->status,
        ));
    }
}
