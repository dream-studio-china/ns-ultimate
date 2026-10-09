<?php

declare(strict_types=1);

namespace App\Wallet\Service\Concern;

/**
 * Provides wrapInTransaction() for services that manage their own EntityManager
 * and ManagerRegistry. Rolls back and automatically recovers (resetManager) a
 * closed EntityManager after an error.
 */
trait WrapInTransactionTrait
{
    /**
     * Run a callable inside a DB transaction. Flushes before commit, rolls back
     * on any Throwable, and recovers a closed EntityManager so the service stays
     * usable after e.g. a connection failure.
     *
     * @template T
     * @param callable(\Doctrine\ORM\EntityManagerInterface): T $fn
     * @return T
     */
    protected function wrapInTransaction(callable $fn): mixed
    {
        $this->em->beginTransaction();
        try {
            $result = $fn($this->em);
            $this->em->flush();
            $this->em->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->rollback();
                }
            } catch (\Throwable $ignored) {
                // The connection may be broken; recovery below still applies.
            }
            if (!$this->em->isOpen()) {
                $this->registry->resetManager();
                /** @var \Doctrine\ORM\EntityManagerInterface $newEm */
                $newEm = $this->registry->getManager();
                $this->em = $newEm;
            }
            throw $e;
        }
    }
}
