<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Store\Entity\Store;
use App\Store\Entity\Product;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

/** Builds durable Trade order snapshots for print rules. */
final class TradeOrderPrintAdapter
{
    public const SCENE_ORDER = 'order';
    public const SCENE_PER_ITEM = 'per_item';
    public const SCENE_FILTERED_LIST = 'filtered_list';
    public const SCENE_FULL_LIST = 'full_list';
    public const SCENE_SETTLEMENT = 'settlement';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array<string, mixed>> */
    public function buildPrintData(Order $order, Store $store, string $scene): array
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
                if (count($tickets) >= 500) {
                    throw new \OverflowException('Per-item printing exceeds the limit of 500 tickets.');
                }
                $ticket = $base;
                $ticket['order']['items'] = [$item];
                $ticket['item'] = $item;
                $ticket['item']['index'] = $index + 1;
                $ticket['item']['total'] = $item['quantity'];
                $tickets[] = $ticket;
            }
        }
        return $tickets;
    }
}
