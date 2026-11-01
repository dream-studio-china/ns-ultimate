<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Core\Utils\UUID;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use App\Store\Entity\Store;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\CounterSession;
use NsUltimate\Business\Counter\Service\CounterSessionService;
use NsUltimate\Business\Counter\Service\DiningModeSnapshotResolver;

/**
 * Temporary monolith adapter. Trade-specific order metadata is intentionally
 * isolated here so Counter's domain model does not depend on Trade.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TradeCounterAdapter
{
    public function __construct(private readonly DiningModeSnapshotResolver $diningModes) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('TradeCounterAdapter requires a Doctrine ORM entity manager.');
        }
        $unitOfWork = $entityManager->getUnitOfWork();
        $storeUuidByOrder = [];
        $metadataByOrder = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof OrderStoreLifecycle) {
                $storeUuidByOrder[$entity->getTradeOrderUuid()] = $entity->getStoreUuid();
            }
            if ($entity instanceof Order) {
                $metadataByOrder[$entity->getUuid()] = $entity->getMetadata() ?? [];
            }
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof Order) {
                continue;
            }

            $metadata = $entity->getMetadata() ?? [];
            $counterUuids = $metadata['counterUuids'] ?? null;
            if ($counterUuids === null && isset($metadata['counterUuid'])) {
                $counterUuids = [$metadata['counterUuid']];
            }
            if ($counterUuids === null || $counterUuids === []) {
                continue;
            }
            if (!is_array($counterUuids) || !array_is_list($counterUuids)) {
                throw new \InvalidArgumentException('metadata.counterUuids must be a list of UUIDs.');
            }

            // The client can submit arbitrary Order.metadata. Store ownership
            // therefore comes from Trade's trusted lifecycle record instead.
            $storeUuid = $storeUuidByOrder[$entity->getUuid()]
                ?? $entityManager->getRepository(OrderStoreLifecycle::class)
                    ->findOneBy(['tradeOrderUuid' => $entity->getUuid()])
                    ?->getStoreUuid();
            if (!is_string($storeUuid) || !UUID::is_valid($storeUuid)) {
                throw new \InvalidArgumentException('A store-scoped order lifecycle is required when counter metadata is provided.');
            }

            foreach (array_unique($counterUuids) as $counterUuid) {
                if (!is_string($counterUuid) || !UUID::is_valid($counterUuid)) {
                    throw new \InvalidArgumentException('metadata.counterUuids entries must be valid UUIDs.');
                }

                $counter = $entityManager->getRepository(Counter::class)->findOneBy(['uuid' => $counterUuid]);
                if (!$counter instanceof Counter || !$counter->isEnabled()) {
                    throw new \InvalidArgumentException('Counter is not available.');
                }
                if ($counter->getStoreUuid() !== $storeUuid) {
                    throw new \InvalidArgumentException('Counter does not belong to the order store.');
                }

                $binding = (new OrderCounter())
                    ->setTradeOrderUuid($entity->getUuid())
                    ->setCounter($counter)
                    ->setStoreUuid($storeUuid);
                $entityManager->persist($binding);
                $unitOfWork->computeChangeSet($entityManager->getClassMetadata(OrderCounter::class), $binding);
            }
        }

        // This covers automatic, store-manual, and administrator bindings alike.
        // onFlush precedes ORM's implicit transaction: callers must open one explicitly.
        $sessions = new CounterSessionService($entityManager);
        $bindings = array_filter($unitOfWork->getScheduledEntityInsertions(), static fn (object $entity): bool => $entity instanceof OrderCounter);
        usort($bindings, static fn (OrderCounter $a, OrderCounter $b): int => ($a->getCounter()?->getId() ?? 0) <=> ($b->getCounter()?->getId() ?? 0));
        foreach ($bindings as $binding) {
            $session = $sessions->assign($binding);
            $store = $entityManager->getRepository(Store::class)->findOneBy(['uuid' => $binding->getStoreUuid()]);
            $this->diningModes->bind($store, $session, ($metadataByOrder[$binding->getTradeOrderUuid()] ?? [])['diningModeUuid'] ?? null);
            if ($session->getId() === null) {
                $unitOfWork->computeChangeSet($entityManager->getClassMetadata(CounterSession::class), $session);
            } else {
                $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata(CounterSession::class), $session);
            }
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata(OrderCounter::class), $binding);
        }
    }
}
