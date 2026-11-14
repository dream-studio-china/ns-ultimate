<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Public;

use App\Core\Utils\UUID;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\Promotion\Service\OrderPromotionSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Public read-only payment-page data, never a payment initiation endpoint. */
final class OrderPaymentController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HuifuProfileResolver $huifu,
    ) {}

    #[Route('/public/orders/{orderUuid}/payment', name: 'public-order-payment', methods: ['GET'], requirements: ['orderUuid' => '[0-9a-fA-F-]{36}'])]
    public function payment(string $orderUuid): JsonResponse
    {
        if (!UUID::is_valid($orderUuid)) return $this->error(404, 'order_not_found');
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => strtolower($orderUuid)]);
        if (!$order instanceof Order) return $this->error(404, 'order_not_found');
        $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
        if (!$lifecycle instanceof OrderStoreLifecycle) return $this->error(404, 'order_not_found');
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $lifecycle->getStoreUuid()]);
        if (!$store instanceof Store || !$store->isActive()) return $this->error(404, 'order_not_found');

        $invoice = null;
        if ($order->getInvoiceId() !== null) {
            $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $order->getInvoiceId()]);
            if (!$invoice instanceof Invoice || $invoice->getSourceType() !== 'trade_order'
                || $invoice->getSourceId() !== $order->getUuid() || $invoice->getScene() !== Invoice::SCENE_ORDER
                || $invoice->getAmount() !== $order->getTotalAmount() || $invoice->getCurrency() !== $order->getCurrency()) {
                return $this->error(409, 'order_payment_conflict');
            }
        }

        $items = [];
        $itemOriginalAmount = 0;
        foreach ($order->getItems() as $item) {
            $name = $item->getProductSnapshot()['name'] ?? '';
            $items[] = ['name' => is_string($name) ? $name : '', 'specification' => $item->getSpecificationTitle() ?? '',
                'quantity' => $item->getQuantity(), 'unitPrice' => $item->getUnitPrice(), 'amount' => $item->getPrice()];
            $itemOriginalAmount += $item->getUnitPrice() * $item->getQuantity();
        }
        $channels = $this->huifu->publicChannels($store->getUuid());
        $counter = $this->publicCounter($order, $store->getUuid());
        $discountSummary = OrderPromotionSnapshot::summary($order->getMetadata(), $order->getTotalAmount(), $itemOriginalAmount);

        return new JsonResponse(['code' => 0, 'message' => 'SUCCESS', 'data' => [
            'orderUuid' => $order->getUuid(), 'status' => $order->getStatus(),
            'store' => ['uuid' => $store->getUuid(), 'name' => $store->getName()],
            'counter' => $counter,
            'currency' => $order->getCurrency(), 'amount' => $order->getTotalAmount(), ...$discountSummary, 'items' => $items,
            'provider' => 'huifu', 'channels' => $channels['channels'], 'defaultChannel' => $channels['defaultChannel'],
            'invoice' => $invoice === null ? null : [
                'uuid' => $invoice->getUuid(), 'outTradeNo' => $invoice->getOutTradeNo(), 'status' => $invoice->getStatus(),
                'amount' => $invoice->getAmount(), 'currency' => $invoice->getCurrency(),
                'payment' => $invoice->getPayment(), 'refundedAmount' => $invoice->getRefundedAmount(),
                'paidAt' => $invoice->getPaidAt()?->format(DATE_ATOM),
            ],
        ]], headers: ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization']);
    }

    /**
     * Return only the counter already bound to this order and store.
     *
     * @return array{uuid: string, name: string, code: string}|null
     */
    private function publicCounter(Order $order, string $storeUuid): ?array
    {
        $binding = $this->em->getRepository(OrderCounter::class)
            ->findOneBy(['tradeOrderUuid' => $order->getUuid(), 'storeUuid' => $storeUuid], ['id' => 'ASC']);
        if ($binding instanceof OrderCounter) {
            return [
                'uuid' => $binding->getCounter()?->getUuid() ?? '',
                'name' => $binding->getCounterNameSnapshot(),
                'code' => $binding->getCounterCodeSnapshot(),
            ];
        }

        // Older orders may predate counter_order bindings; accept only a
        // counter UUID from their own metadata and verify store ownership.
        $metadata = $order->getMetadata() ?? [];
        $counterUuid = $metadata['counterUuid'] ?? null;
        if ($counterUuid === null && is_array($metadata['counterUuids'] ?? null)) {
            $counterUuid = $metadata['counterUuids'][0] ?? null;
        }
        if (!is_string($counterUuid) || !UUID::is_valid($counterUuid)) return null;

        $counter = $this->em->getRepository(Counter::class)
            ->findOneBy(['uuid' => strtolower($counterUuid), 'storeUuid' => $storeUuid]);
        if (!$counter instanceof Counter) return null;

        return ['uuid' => $counter->getUuid(), 'name' => $counter->getName(), 'code' => $counter->getCode()];
    }

    private function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['code' => $status, 'message' => $message], $status,
            ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization']);
    }
}
