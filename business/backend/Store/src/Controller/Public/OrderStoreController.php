<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Public;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, minimal order-to-store lookup. It exposes only the order and store
 * UUIDs; store ownership comes from the trusted Trade lifecycle record, never
 * from order metadata or caller input.
 */
final class OrderStoreController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/public/orders/{orderUuid}/store', name: 'public-order-store', methods: ['GET'], requirements: ['orderUuid' => '[0-9a-fA-F-]{36}'])]
    public function store(string $orderUuid): JsonResponse
    {
        if (!UUID::is_valid($orderUuid)) {
            return $this->error(404, 'order_not_found');
        }
        $order = $this->em->getRepository(Order::class)->findOneBy(['uuid' => strtolower($orderUuid)]);
        if (!$order instanceof Order) {
            return $this->error(404, 'order_not_found');
        }
        $lifecycle = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $order->getUuid()]);
        if (!$lifecycle instanceof OrderStoreLifecycle) {
            return $this->error(404, 'order_not_found');
        }
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $lifecycle->getStoreUuid()]);
        if (!$store instanceof Store || !$store->isActive()) {
            return $this->error(404, 'order_not_found');
        }

        return new JsonResponse(['code' => 0, 'message' => 'SUCCESS', 'data' => [
            'orderUuid' => $order->getUuid(),
            'storeUuid' => $store->getUuid(),
        ]], headers: ['Cache-Control' => 'no-store']);
    }

    private function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['code' => $status, 'message' => $message], $status, ['Cache-Control' => 'no-store']);
    }
}
