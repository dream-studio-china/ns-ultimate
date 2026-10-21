<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Store\Entity\Store;
use App\Store\Entity\Product;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;

/** Translates Trade lifecycle events and per-store print settings into durable print requests. */
final class TradeOrderPrintAdapter
{
    public const TRIGGER_PAID = 'paid';
    public const TRIGGER_ACCEPTED = 'accepted';
    public const SCENE_PER_ITEM = 'per_item';
    public const SCENE_FILTERED_LIST = 'filtered_list';
    public const SCENE_FULL_LIST = 'full_list';
    public const SCENE_SETTLEMENT = 'settlement';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function enqueueForPaidOrder(Order $order): void
    {
        if ($order->getStatus() !== Order::STATUS_PAID) {
            return;
        }
        $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
        if (!$lifecycle instanceof OrderStoreLifecycle) {
            return;
        }
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $lifecycle->getStoreUuid()]);
        if ($store instanceof Store) {
            $this->enqueue($order, $store, self::TRIGGER_PAID);
        }
    }

    public function enqueueForAcceptedOrder(string $orderUuid, string $storeUuid): void
    {
        $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $orderUuid]);
        if (!$lifecycle instanceof OrderStoreLifecycle
            || $lifecycle->getStoreUuid() !== $storeUuid) {
            return;
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        if ($order instanceof Order && $store instanceof Store) {
            $this->enqueue($order, $store, self::TRIGGER_ACCEPTED);
            $this->entityManager->flush();
        }
    }

    private function enqueue(Order $order, Store $store, string $trigger): void
    {
        $settings = $store->getSettings() ?? [];
        $printing = $settings['printing'] ?? null;
        if (!is_array($printing)) {
            return;
        }
        $productionTrigger = $printing['productionTrigger'] ?? 'accepted';
        if (!in_array($productionTrigger, [self::TRIGGER_ACCEPTED, self::TRIGGER_PAID], true)) {
            return;
        }
        $routes = $printing['routes'] ?? [];
        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $routeKey => $route) {
            if (!is_string($routeKey) || !is_array($route) || ($route['enabled'] ?? true) !== true) {
                continue;
            }
            $scene = $route['scene'] ?? null;
            if (!in_array($scene, [self::SCENE_PER_ITEM, self::SCENE_FILTERED_LIST, self::SCENE_FULL_LIST, self::SCENE_SETTLEMENT], true)) {
                continue;
            }
            $routeTrigger = $scene === self::SCENE_SETTLEMENT ? self::TRIGGER_PAID : $productionTrigger;
            if ($routeTrigger !== $trigger
                || !is_string($route['deviceUuid'] ?? null)
                || !is_string($route['templateUuid'] ?? null)) {
                continue;
            }
            $copies = $route['copies'] ?? 1;
            if (!is_int($copies) || $copies < 1 || $copies > 100) {
                continue;
            }

            $configuration = [
                'scene' => $scene,
                'deviceUuid' => $route['deviceUuid'],
                'templateUuid' => $route['templateUuid'],
                'copies' => $copies,
            ];
            $requestKey = hash('sha256', implode(':', [$order->getUuid(), $trigger, $routeKey]));
            foreach ($this->buildPrintData($order, $store, $scene) as $ticketIndex => $printData) {
                $ticketKey = $scene === self::SCENE_PER_ITEM ? ':'.$ticketIndex : '';
                $key = hash('sha256', $requestKey.$ticketKey);
                $existing = $this->entityManager->getRepository(PrinterOrderRequest::class)->findOneBy(['requestKey' => $key]);
                if ($existing instanceof PrinterOrderRequest) {
                    continue;
                }
                $this->entityManager->persist(new PrinterOrderRequest(
                    $key,
                    $order->getUuid(),
                    $store->getUuid(),
                    $routeKey,
                    $trigger,
                    $configuration,
                    $printData,
                ));
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function buildPrintData(Order $order, Store $store, string $scene): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProductSnapshot() ?? [];
            $specification = $item->getSpecSnapshot() ?? [];
            $productSettings = is_array($product['settings'] ?? null) ? $product['settings'] : [];
            // Product settings are not yet part of the core Trade item snapshot. Freeze
            // the current opt-in onto this durable request until Trade snapshots it.
            if (is_string($product['uuid'] ?? null)) {
                $currentProduct = $this->entityManager->getRepository(Product::class)->findOneBy(['uuid' => $product['uuid']]);
                if ($currentProduct instanceof Product && method_exists($currentProduct, 'getSettings')) {
                    $settings = $currentProduct->getSettings();
                    if (is_array($settings)) {
                        $productSettings = $settings;
                    }
                }
            }
            $items[] = [
                'name' => (string) ($product['name'] ?? $item->getSpecificationTitle() ?? ''),
                'specification' => (string) ($specification['name'] ?? $item->getSpecificationTitle() ?? ''),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $item->getUnitPrice(),
                'amount' => $item->getPrice(),
                'productUuid' => $product['uuid'] ?? null,
                'productSettings' => $productSettings,
            ];
        }
        if ($scene === self::SCENE_FILTERED_LIST) {
            $items = array_values(array_filter($items, static fn (array $item): bool => ($item['productSettings']['kitchenPrinterEnable'] ?? false) === true));
            if ($items === []) {
                return [];
            }
        }
        $base = [
            'order' => [
                'uuid' => $order->getUuid(),
                'number' => $order->getId() === null ? $order->getUuid() : (string) $order->getId(),
                'status' => $order->getStatus(),
                'createdAt' => $order->getCreatedAt()->format(DATE_ATOM),
                'paidAt' => $order->getPaidAt()?->format(DATE_ATOM),
                'currency' => $order->getCurrency(),
                'totalAmount' => $order->getTotalAmount(),
                'items' => $items,
            ],
            'store' => ['uuid' => $store->getUuid(), 'name' => $store->getName(), 'code' => $store->getCode()],
        ];
        if ($scene !== self::SCENE_PER_ITEM) {
            return [$base];
        }
        $tickets = [];
        foreach ($items as $item) {
            for ($index = 0; $index < $item['quantity']; ++$index) {
                $ticket = $base;
                $ticket['order']['items'] = [$item];
                $ticket['item'] = $item;
                $ticket['item']['index'] = $index + 1;
                $ticket['item']['total'] = $item['quantity'];
                $tickets[] = $ticket;
                if (count($tickets) >= 500) {
                    return $tickets;
                }
            }
        }
        return $tickets;
    }
}
