<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Adapter;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** The shared payment response can now contain customer Huifu launch parameters. */
final class CustomerPaymentResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => 'onResponse']; }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->attributes->get('_route') !== 'app-orders-payment') return;
        $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
        $event->getResponse()->setVary('Authorization', false);
    }
}
