<?php
declare(strict_types=1);
namespace App\Trade\Event;

use App\Trade\Entity\Order;

/** Dispatched after an order is fulfilled (shipped). */
final class OrderFulfilledEvent
{
    public function __construct(public readonly Order $order) {}
}
