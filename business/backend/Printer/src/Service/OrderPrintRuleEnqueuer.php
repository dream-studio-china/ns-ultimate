<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Adapter\TradeOrderPrintAdapter;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;

/** Create durable snapshots for matching rules within the caller's transaction. */
final class OrderPrintRuleEnqueuer
{
    public function __construct(
        private readonly OrderPrintRuleSelector $selector,
        private readonly OrderPrintRequestWriter $writer,
        private readonly TradeOrderPrintAdapter $snapshots,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function enqueue(Order $order, Store $store, string $event): void
    {
        foreach ($this->selector->select($store->getUuid(), $event, $order) as $rule) {
            $configuration = $rule->toRule();
            $template = $this->entityManager->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $rule->getTemplateUuid()]);
            $device = $this->entityManager->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $rule->getDeviceUuid()]);
            if ($template instanceof PrinterTemplate && $device instanceof PrinterDevice
                && $device->getStoreUuid() === $store->getUuid() && $template->getKind() === $device->getKind()) {
                $definition = $template->getDefinition();
                if ($device->getKind() === PrinterDevice::KIND_LABEL) {
                    if ($device->getWidthMm() !== null) { $definition['widthMm'] = $device->getWidthMm(); }
                    if ($device->getHeightMm() !== null) { $definition['heightMm'] = $device->getHeightMm(); }
                }
                $configuration['templateSnapshot'] = $definition;
            }
            try {
                $tickets = $this->snapshots->buildPrintData($order, $store, $rule->getScene());
            } catch (\OverflowException $exception) {
                if ($this->writer->claim($order->getUuid(), $rule->getUuid())) {
                    $key = hash('sha256', json_encode([$order->getUuid(), $rule->getUuid(), 0], JSON_THROW_ON_ERROR));
                    $request = new PrinterOrderRequest($key, $order->getUuid(), $store->getUuid(), $rule->getUuid(), $event, $configuration, []);
                    $request->markFailed($exception->getMessage());
                    $this->writer->insert($request);
                }
                continue;
            }
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
