<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Identity\Entity\User;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\DTO\StoreSettings;
use App\Store\Service\StoreOrderServiceInterface;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Store-owned order writes for authenticated employees. Counter owns the surrounding installation
 * context (session, binding, replacement eligibility); this service keeps no counter assumptions.
 */
final class StaffOrderService
{
    public function __construct(
        private readonly OrderServiceInterface $orders,
        private readonly StoreOrderServiceInterface $storeOrders,
        #[Target('state_machine.order')] private readonly WorkflowInterface $workflow,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function create(Store $store, PriceCalculationResult $calculation, ?User $customer, ?string $notes, array $metadata): Order
    {
        $settings = StoreSettings::from($store->getSettings());
        $context = new StoreContext($store->getUuid(), $store->getCode(), $store->getName(), 'staff', $store->getCurrency(), $settings->requireAcceptance, $settings->requireInventory, $settings->requireVerification);
        $order = $this->orders->createOrder($calculation->items, $customer, $calculation->totalAmount, $store->getCurrency(), $notes, $metadata, $context);
        // Employee-created rounds are real orders, not drafts. Keep the state machine and
        // Store acceptance/inventory policy authoritative: confirmation only happens when
        // the configured policy permits it.
        if ($this->workflow->can($order, 'confirm')) {
            $this->workflow->apply($order, 'confirm');
        }
        return $order;
    }

    /** Cancels the Trade order and its projected Store order in the caller's transaction. */
    public function cancel(Order $order, StoreOrder $storeOrder): void
    {
        if (!$this->workflow->can($order, 'cancel')) throw new HttpException(409, 'staff_order_locked');
        $this->orders->cancel($order);
        $this->workflow->apply($order, 'cancel');
        $this->storeOrders->cancel($storeOrder);
    }
}
