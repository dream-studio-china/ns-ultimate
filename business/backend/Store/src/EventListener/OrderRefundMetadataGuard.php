<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\EventListener;

use App\Trade\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use NsUltimate\Business\Store\Service\OrderRefundMetadata;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final readonly class OrderRefundMetadataGuard
{
    public function __construct(private OrderRefundMetadata $receipts) {}

    public function prePersist(PrePersistEventArgs $event): void
    {
        $order = $event->getObject();
        if (!$order instanceof Order || !array_key_exists(OrderRefundMetadata::KEY, $order->getMetadata() ?? [])) return;
        $metadata = $order->getMetadata();
        unset($metadata[OrderRefundMetadata::KEY]);
        $order->setMetadata($metadata);
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $order = $event->getObject();
        if (!$order instanceof Order || !$event->hasChangedField('metadata') || $this->receipts->isAuthorized($order)) return;
        $connection = $event->getObjectManager()->getConnection();
        // preUpdate runs inside the flush transaction. A current locking read
        // preserves receipts even when this entity was loaded before a refund.
        $lock = $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
        $raw = $connection->fetchOne('SELECT metadata FROM trade_order WHERE id = ?'.$lock, [$order->getId()]);
        $stored = $raw === null || $raw === false ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $metadata = $order->getMetadata();
        if (array_key_exists(OrderRefundMetadata::KEY, $stored)) {
            $metadata = [...($metadata ?? []), OrderRefundMetadata::KEY => $stored[OrderRefundMetadata::KEY]];
        } elseif (is_array($metadata)) {
            unset($metadata[OrderRefundMetadata::KEY]);
        }
        $order->setMetadata($metadata);
        $event->setNewValue('metadata', $metadata);
    }
}
