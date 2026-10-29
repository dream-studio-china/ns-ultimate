<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Store\Entity\Store;
use App\Store\Entity\Product;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Service\OrderItemFilter;
use NsUltimate\Business\Printer\Value\PrintCategoryContext;
use NsUltimate\Business\Printer\Value\PrintItemContext;
use NsUltimate\Business\Printer\Value\PrintProductContext;

/** Builds durable Trade order snapshots for print rules. */
final class TradeOrderPrintAdapter
{
    private readonly OrderItemFilter $filter;

    public function __construct(private readonly EntityManagerInterface $entityManager, ?OrderItemFilter $filter = null)
    {
        $this->filter = $filter ?? new OrderItemFilter();
    }

    /** @return list<array<string, mixed>> */
    public function buildPrintData(Order $order, Store $store, bool $perUnit = false, ?string $itemFilter = null): array
    {
        $filtered = $itemFilter !== null && trim($itemFilter) !== '';
        $items = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProductSnapshot() ?? [];
            $specification = $item->getSpecSnapshot() ?? [];
            $productSettings = is_array($product['settings'] ?? null) ? $product['settings'] : [];
            $currentProduct = null;
            // Product settings are not yet part of the core Trade item snapshot. Freeze
            // the current opt-in onto this durable request until Trade snapshots it.
            if (is_string($product['uuid'] ?? null)) {
                $candidate = $this->entityManager->getRepository(Product::class)->findOneBy(['uuid' => $product['uuid']]);
                if ($candidate instanceof Product) {
                    $currentProduct = $candidate;
                    if (method_exists($candidate, 'getSettings')) {
                        $settings = $candidate->getSettings();
                        if (is_array($settings)) {
                            $productSettings = $settings;
                        }
                    }
                }
            }
            $data = [
                'name' => (string) ($product['name'] ?? $item->getSpecificationTitle() ?? ''),
                'specification' => (string) ($specification['name'] ?? $item->getSpecificationTitle() ?? ''),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $item->getUnitPrice(),
                'amount' => $item->getPrice(),
                'productUuid' => $product['uuid'] ?? null,
                'productSettings' => $productSettings,
            ];
            if ($filtered) {
                $category = $currentProduct?->getCategory();
                $productContext = $currentProduct instanceof Product
                    ? new PrintProductContext(
                        $currentProduct->getUuid(),
                        $currentProduct->getName(),
                        $productSettings,
                        $category === null ? null : new PrintCategoryContext($category->getUuid(), $category->getName(), $category->getSlug()),
                    )
                    : null;
                if (!$this->filter->matches($itemFilter, new PrintItemContext($data, $productSettings, $productContext))) {
                    continue;
                }
            }
            $items[] = $data;
        }
        if ($filtered && $items === []) {
            return [];
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
        if (!$perUnit) {
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
