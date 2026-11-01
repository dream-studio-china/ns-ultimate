<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Trade\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Entity\OrderCounter;

final class CounterSessionService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** The caller owns the transaction, including the eventual flush. */
    public function lockCounter(Counter $counter): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Counter session operations require an explicit transaction before flush.');
        }
        if ($counter->getId() === null) {
            throw new \LogicException('Counter session operations require a persisted counter.');
        }
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // SQLite has no row locks: acquire its writer lock before reading session state.
            $connection->executeStatement('UPDATE counter SET id = id WHERE id = ?', [$counter->getId()]);
        } else {
            $this->em->lock($counter, LockMode::PESSIMISTIC_WRITE);
        }
    }

    public function activeSession(Counter $counter): ?CounterSession
    {
        $this->lockCounter($counter);
        // A locking/current read avoids an older MySQL REPEATABLE READ snapshot.
        return $this->em->createQueryBuilder()->select('session')->from(CounterSession::class, 'session')
            ->where('session.counter = :counter')->andWhere('session.endedAt IS NULL')
            ->setParameter('counter', $counter)->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    public function assign(OrderCounter $binding): CounterSession
    {
        $counter = $binding->getCounter();
        if (!$counter instanceof Counter) {
            throw new \InvalidArgumentException('A counter is required for an order binding.');
        }
        $session = $this->activeSession($counter);
        // Reuse an unflushed session created earlier in this same unit of work.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof CounterSession && $scheduled->getCounter() === $counter && $scheduled->getEndedAt() === null) {
                $session = $scheduled;
                break;
            }
        }
        if (!$session instanceof CounterSession) {
            $session = new CounterSession($counter);
            $this->em->persist($session);
        }
        // Never trust a client-supplied session, including one that has already ended.
        $binding->setCounterSession($session);
        return $session;
    }

    public function end(Counter $counter): CounterSession
    {
        $session = $this->activeSession($counter);
        if (!$session instanceof CounterSession) {
            throw new \DomainException('There is no active counter session.');
        }
        $bindings = $this->em->createQueryBuilder()->select('binding')->from(OrderCounter::class, 'binding')
            ->where('binding.counterSession = :session')->setParameter('session', $session)
            ->orderBy('binding.tradeOrderUuid', 'ASC')
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult();
        foreach ($bindings as $binding) {
            $order = $this->em->createQueryBuilder()->select('tradeOrder')->from(Order::class, 'tradeOrder')
                ->where('tradeOrder.uuid = :uuid')->setParameter('uuid', $binding->getTradeOrderUuid())
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)
                ->getOneOrNullResult();
            if (!$order instanceof Order || !in_array($order->getStatus(), [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED, 'merged'], true)) {
                throw new \DomainException('The session has missing orders or orders that are not yet closed.');
            }
        }
        $session->end();
        return $session;
    }
}
