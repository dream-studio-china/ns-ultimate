<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoicePaidEvent;
use App\Trade\Service\OrderInvoiceSourcePolicy;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class MergedOrderPaymentSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return [InvoicePaidEvent::class => ['onInvoicePaid', -10]];
    }

    public function onInvoicePaid(InvoicePaidEvent $event): void
    {
        $invoice = $event->getInvoice();
        if ($invoice->getSourceType() !== OrderInvoiceSourcePolicy::getSourceType()) {
            return;
        }
        $followup = $invoice->getExtraData()[Invoice::EXTRA_PAID_FOLLOWUP] ?? null;
        if (!is_array($followup) || ($followup['orderStatus'] ?? null) !== 'merged') {
            return;
        }
        $merge = $this->em->getRepository(OrderMerge::class)->findOneBy(['sourceOrderUuid' => $invoice->getSourceId()]);
        if ($merge instanceof OrderMerge) {
            $invoice->appendExtraData(Invoice::EXTRA_PAID_FOLLOWUP, [
                ...$followup,
                'mergedIntoOrderUuid' => $merge->getTargetOrderUuid(),
            ]);
        }
    }
}
