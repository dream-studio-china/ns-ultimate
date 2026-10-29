<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Trade\Event\OrderPaidEvent;
use App\Trade\Entity\Order;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

final class TradeOrderPrintEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TradeOrderPrintAdapter $adapter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [OrderPaidEvent::class => 'onOrderPaid', 'workflow.order.completed.pay' => 'onPaymentCompleted'];
    }

    public function onOrderPaid(OrderPaidEvent $event): void
    {
        // Persist request intent in the same transaction; never contact a printer here.
        $this->adapter->enqueueForPaidOrder($event->order);
    }

    public function onPaymentCompleted(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof Order) {
            $this->adapter->enqueueForPaidSettlement($order);
        }
    }
}
