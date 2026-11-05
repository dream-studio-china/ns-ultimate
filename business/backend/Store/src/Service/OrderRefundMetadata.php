<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Trade\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\DTO\OrderRefundReceipt;

/** Owns only the server-controlled receipt namespace, never the full metadata. */
final class OrderRefundMetadata
{
    public const KEY = 'storeRefundRequests';
    /** @var \WeakMap<Order, array<string, mixed>> */
    private \WeakMap $writes;

    public function __construct(private readonly EntityManagerInterface $em) { $this->writes = new \WeakMap(); }

    public function isAuthorized(Order $order): bool
    {
        return isset($this->writes[$order]) && ($order->getMetadata()[self::KEY] ?? null) === $this->writes[$order];
    }

    /** @return array<string, array<string, mixed>> */
    public function entries(string $orderUuid): array
    {
        $connection = $this->em->getConnection();
        $lock = $connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof SQLitePlatform ? ' FOR UPDATE' : '';
        $raw = $connection->fetchOne('SELECT metadata FROM trade_order WHERE uuid = ?'.$lock, [$orderUuid]);
        $metadata = $raw === null || $raw === false ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        return $metadata[self::KEY] ?? [];
    }

    /** The caller owns the order-row lock and an active transaction. */
    public function save(Order $order, OrderRefundReceipt $receipt): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Receipt writes require the order transaction.');
        $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
        $metadata = $order->getMetadata() ?? [];
        $entries = $metadata[self::KEY] ?? [];
        $entries[$receipt->requestHash] = $receipt->toArray();
        $order->setMetadata([...$metadata, self::KEY => $entries]);
        $this->writes[$order] = $entries;
        try { $this->em->flush(); }
        finally { unset($this->writes[$order]); }
    }
}
