<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

/** A settlement cannot be cancelled after its source orders have been frozen. */
final class SettlementWorkflowGuard implements EventSubscriberInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return ['workflow.order.guard.cancel' => 'guardCancel'];
    }

    public function guardCancel(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof Order && $this->em->getRepository(OrderMerge::class)->count(['targetOrderUuid' => $order->getUuid()]) > 0) {
            $event->setBlocked(true, 'A merged settlement cannot be cancelled.');
        }
    }
}
