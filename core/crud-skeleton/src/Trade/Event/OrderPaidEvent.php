<?php
declare(strict_types=1);
namespace App\Trade\Event;

use App\Trade\Entity\Order;

/** Dispatched after an order transitions to "paid" status. */
final class OrderPaidEvent
{
    public function __construct(public readonly Order $order) {}
}
