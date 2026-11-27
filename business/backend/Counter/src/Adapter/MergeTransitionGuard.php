<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Payment\Entity\Invoice;
use App\Trade\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class MergeTransitionGuard implements EventSubscriberInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.order.guard.merge' => 'guard',
            'workflow.invoice.guard.start_pay' => 'guardInvoicePayment',
        ];
    }

    /** @param GuardEvent<object> $event */
    public function guard(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof Order || $this->em->getRepository(OrderMerge::class)->findOneBy(['sourceOrderUuid' => $order->getUuid()]) === null) {
            $event->setBlocked(true, 'Orders can only be merged through the counter merge operation.');
        }
    }

    /** @param GuardEvent<object> $event */
    public function guardInvoicePayment(GuardEvent $event): void
    {
        $invoice = $event->getSubject();
        if (!$invoice instanceof Invoice || $invoice->getSourceType() !== 'trade_order') {
            return;
        }
        $query = $this->em->createQueryBuilder()->select('m')->from(OrderMerge::class, 'm')
            ->where('m.sourceOrderUuid = :source')->setParameter('source', $invoice->getSourceId())->getQuery();
        $connection = $this->em->getConnection();
        // Core holds the source order lock when starting payment; do not read a stale snapshot.
        if ($connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->setLockMode(LockMode::PESSIMISTIC_READ);
        }
        if ($query->getOneOrNullResult() !== null) {
            $event->setBlocked(true, 'Merged source orders cannot start payment.');
        }
    }
}
