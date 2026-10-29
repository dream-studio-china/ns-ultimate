<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class MergeTransitionGuard implements EventSubscriberInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return ['workflow.order.guard.merge' => 'guard'];
    }

    public function guard(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof Order || $this->em->getRepository(OrderMerge::class)->findOneBy(['sourceOrderUuid' => $order->getUuid()]) === null) {
            $event->setBlocked(true, 'Orders can only be merged through the counter merge operation.');
        }
    }
}
