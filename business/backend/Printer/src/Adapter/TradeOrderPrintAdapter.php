<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Adapter;

use App\Store\Entity\Store;
use App\Store\Entity\Product;
use App\Trade\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Printer\Service\OrderItemFilter;
use NsUltimate\Business\Printer\Value\PrintCategoryContext;
use NsUltimate\Business\Printer\Value\PrintItemContext;
use NsUltimate\Business\Printer\Value\PrintProductContext;
use Symfony\Component\Intl\Currencies;

/** Builds durable Trade order snapshots for print rules. */
final class TradeOrderPrintAdapter
{
    private readonly OrderItemFilter $filter;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?OrderItemFilter $filter = null,
        private readonly string $paymentBaseUrl = '',
    ) {
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
            $data['product'] = $data['name'];
            $data['spec'] = $data['specification'];
            // Display subtitle lives in the current product metadata, which the core
            // Trade item snapshot does not carry; fall back to the specification name.
            $productMetadata = $currentProduct?->getMetadata();
            $subtitle = is_array($productMetadata) && is_string($productMetadata['subtitle'] ?? null)
                ? trim($productMetadata['subtitle'])
                : '';
            $data['subTitle'] = $subtitle !== '' ? $subtitle : $data['specification'];
            $data['quantityLabel'] = 'x'.$item->getQuantity();
            $data['price'] = $data['amount'];
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
        $counterNames = [];
        $counterLabels = [];
        $bindings = $this->entityManager->getRepository(OrderCounter::class)->findBy([
            'tradeOrderUuid' => $order->getUuid(),
            'storeUuid' => $store->getUuid(),
        ], ['id' => 'ASC']);
        foreach ($bindings as $binding) {
            $name = (string) $binding;
            $counterNames[] = $name;
            $counterId = $binding->getCounter()?->getId();
            $counterLabels[] = $counterId === null ? $name : $name.'(#'.$counterId.')';
        }
        $counterName = implode(', ', $counterNames);
        $subtotal = 0;
        foreach ($order->getItems() as $item) {
            $subtotal += $item->getPrice();
        }
        $currency = $order->getCurrency();
        $base = [
            'order' => [
                'id' => $order->getId(),
                'uuid' => $order->getUuid(),
                'number' => $order->getId() === null ? $order->getUuid() : (string) $order->getId(),
                'counterName' => $counterName,
                'counterLabel' => implode(', ', $counterLabels),
                'labelHeader' => $counterName,
                'status' => $order->getStatus(),
                'createdAt' => $order->getCreatedAt()->format(DATE_ATOM),
                'createdTime' => $order->getCreatedAt()->format('Y/m/d H:i:s'),
                'paidAt' => $order->getPaidAt()?->format(DATE_ATOM),
                'currency' => $currency,
                'totalAmount' => $order->getTotalAmount(),
                'totalPrice' => $this->money($subtotal, $currency),
                'discount' => $this->money(max(0, $subtotal - $order->getTotalAmount()), $currency),
                'amountDue' => $this->money($order->getTotalAmount(), $currency),
                'paymentUrl' => $this->paymentUrl($order),
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

    /** Render a minor-unit amount the way receipt templates display it. */
    private function money(int $amount, string $currency): string
    {
        $code = strtoupper($currency);
        $digits = Currencies::exists($code) ? Currencies::getFractionDigits($code) : 2;
        $symbol = Currencies::exists($code) ? Currencies::getSymbol($code, 'zh_CN') : $code.' ';
        return $symbol.number_format($amount / 10 ** $digits, $digits, '.', ',');
    }

    /** Build the customer H5 payment link from the configured origin and snapshot identities. */
    private function paymentUrl(Order $order): string
    {
        $base = rtrim(trim($this->paymentBaseUrl), '/');
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        if ($base === '' || !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return $base.'/h5/p.html?uuid='.rawurlencode($order->getUuid());
    }
}
