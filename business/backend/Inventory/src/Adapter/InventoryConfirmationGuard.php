<?php

declare(strict_types=1);

namespace NsUltimate\Business\Inventory\Adapter;

use App\Trade\Entity\Order;
use NsUltimate\Business\Inventory\Service\OrderInventoryAvailability;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class InventoryConfirmationGuard implements EventSubscriberInterface
{
    public function __construct(private readonly OrderInventoryAvailability $inventory) {}

    public static function getSubscribedEvents(): array
    {
        return ['workflow.order.guard.confirm' => 'guard'];
    }

    /** @param GuardEvent<object> $event */
    public function guard(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof Order && !$this->inventory->hasValidInventory($order)) {
            $event->setBlocked(true, 'A valid inventory reservation is required before confirmation.');
        }
    }
}
