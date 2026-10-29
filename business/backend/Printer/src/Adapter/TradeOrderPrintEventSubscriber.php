<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Trade\Entity\Order;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

final class TradeOrderPrintEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OrderPrintRuleEventAdapter $adapter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.order.completed.confirm' => 'onConfirmationCompleted',
            'workflow.order.completed.pay' => 'onPaymentCompleted',
        ];
    }

    public function onConfirmationCompleted(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof Order) {
            $this->adapter->onConfirmed($order);
        }
    }

    public function onPaymentCompleted(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof Order) {
            $this->adapter->onPaid($order);
        }
    }
}
