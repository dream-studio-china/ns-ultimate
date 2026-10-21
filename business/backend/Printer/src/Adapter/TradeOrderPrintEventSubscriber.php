<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Trade\Event\OrderPaidEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class TradeOrderPrintEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TradeOrderPrintAdapter $adapter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [OrderPaidEvent::class => 'onOrderPaid'];
    }

    public function onOrderPaid(OrderPaidEvent $event): void
    {
        // Persist request intent in the same transaction; never contact a printer here.
        $this->adapter->enqueueForPaidOrder($event->order);
    }
}
