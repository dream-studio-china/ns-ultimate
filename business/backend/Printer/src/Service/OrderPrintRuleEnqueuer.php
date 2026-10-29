<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use NsUltimate\Business\Printer\Adapter\TradeOrderPrintAdapter;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;

/** Create durable snapshots for matching rules within the caller's transaction. */
final class OrderPrintRuleEnqueuer
{
    public function __construct(
        private readonly OrderPrintRuleSelector $selector,
        private readonly OrderPrintRequestWriter $writer,
        private readonly TradeOrderPrintAdapter $snapshots,
    ) {}

    public function enqueue(Order $order, Store $store, string $event): void
    {
        foreach ($this->selector->select($store->getUuid(), $event, $order) as $rule) {
            $configuration = $rule->toRule();
            $tickets = $this->snapshots->buildPrintData($order, $store, $rule->getScene());
            if ($tickets === [] || !$this->writer->claim($order->getUuid(), $rule->getUuid())) {
                continue;
            }
            foreach ($tickets as $index => $data) {
                // Event is deliberately excluded: one rule prints once per order across triggers.
                $key = hash('sha256', json_encode([$order->getUuid(), $rule->getUuid(), $index], JSON_THROW_ON_ERROR));
                $this->writer->insert(new PrinterOrderRequest(
                    $key, $order->getUuid(), $store->getUuid(), $rule->getUuid(), $event, $configuration, $data,
                ));
            }
        }
    }
}
