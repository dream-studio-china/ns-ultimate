<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Adapter;

use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Message\StoreOrderAcceptedMessage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

/** Continue replacement confirmation after Trade has consumed the acceptance event. */
#[AsMessageHandler(priority: -100)]
final class ReplacementOrderAcceptedHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Target('state_machine.order')] private readonly WorkflowInterface $workflow,
    ) {}

    public function __invoke(StoreOrderAcceptedMessage $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        if (!is_array($payload) || !is_string($payload['orderUuid'] ?? null) || !is_string($payload['storeUuid'] ?? null)) {
            throw new \InvalidArgumentException('Invalid store.order.accepted.v1 envelope for replacement confirmation.');
        }

        $this->em->wrapInTransaction(function () use ($payload): void {
            $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => $payload['orderUuid']]);
            if (!$order instanceof Order) return;
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getStatus() !== Order::STATUS_PENDING || $order->getInvoiceId() !== null || $order->getPaymentStatus() !== null) return;
            $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy([
                'tradeOrderUuid' => $order->getUuid(), 'storeUuid' => $payload['storeUuid'],
            ]);
            if (!$lifecycle instanceof OrderStoreLifecycle) return;
            $this->em->refresh($lifecycle);
            if ($lifecycle->getAcceptanceStatus() !== OrderStoreLifecycle::ACCEPTANCE_ACCEPTED) return;
            $bindings = $this->em->getRepository(OrderCounter::class)->findBy(['tradeOrderUuid' => $order->getUuid()]);
            if (count($bindings) !== 1 || $bindings[0]->getStoreUuid() !== $payload['storeUuid']) return;

            // Only a committed employee replacement receipt authorizes automatic
            // confirmation. Mutable order metadata is not an authorization source.
            // LIKE narrows JSON candidates on both supported MySQL and SQLite;
            // decoded fields below establish the exact result identity.
            $receipts = $this->em->getConnection()->fetchFirstColumn(
                'SELECT result FROM store_staff_operation WHERE store_uuid = ? AND action = ? AND result LIKE ?',
                [$payload['storeUuid'], 'counter_order.replace.'.$bindings[0]->getCounter()->getUuid(), '%'.$order->getUuid().'%'],
            );
            foreach ($receipts as $receipt) {
                $result = json_decode($receipt, true, flags: JSON_THROW_ON_ERROR);
                if (($result['orderUuid'] ?? null) !== $order->getUuid()
                    || ($result['counterUuid'] ?? null) !== $bindings[0]->getCounter()->getUuid()
                    || !is_string($result['replacedOrderUuid'] ?? null)) continue;
                if ($this->workflow->can($order, 'confirm')) $this->workflow->apply($order, 'confirm');
                return;
            }
        });
    }
}
