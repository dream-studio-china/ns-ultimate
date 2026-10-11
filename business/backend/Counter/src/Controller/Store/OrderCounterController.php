<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\Store;

use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Service\OrderCounterService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/order-counters', name: 'store-order-counters-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class OrderCounterController extends RestController
{
    use StoreScopedAuthorizationApiMixin, \App\Core\View\ListApiViewMixin, \App\Core\View\DetailApiViewMixin,
        \App\Core\View\CreateApiViewMixin, \App\Core\View\DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['tradeOrderUuid', 'counterUuid'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['tradeOrderUuid', 'counterUuid'];

    public function __construct(
        protected readonly OrderCounterService $service,
        private readonly StoreServiceInterface $storeService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    protected function storeScopedFilter(Store $store): array { return ['storeUuid' => $store->getUuid()]; }
    protected function storeService(): StoreServiceInterface { return $this->storeService; }
    protected function storeAuthorizationResource(): string { return 'order_counter'; }

    /** @return array<string, string> */
    protected function storeActionPermissions(): array { return ['read' => 'store:order_counter:read']; }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof OrderCounter) {
            throw new \LogicException('Unexpected order counter entity.');
        }
        $store = $this->storeForAuthorization();
        $orderUuid = $content['tradeOrderUuid'] ?? null;
        if (!is_string($orderUuid) || !UUID::is_valid($orderUuid)) {
            throw new \InvalidArgumentException('tradeOrderUuid must be a valid UUID.');
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        $orderStoreUuid = $order instanceof Order
            ? $this->entityManager->getRepository(OrderStoreLifecycle::class)
                ->findOneBy(['tradeOrderUuid' => $order->getUuid()])
                ?->getStoreUuid()
            : null;
        if (!$order instanceof Order || $orderStoreUuid !== $store->getUuid()) {
            throw new \InvalidArgumentException('Trade order must belong to this store.');
        }
        $counter = $this->resolveCounter($content['counterUuid'] ?? null, $store->getUuid());
        $existing = $this->entityManager->getRepository(OrderCounter::class)->findOneBy([
            'tradeOrderUuid' => $orderUuid,
            'counter' => $counter,
        ]);
        if ($existing instanceof OrderCounter) {
            throw new \InvalidArgumentException('Counter is already bound to this order.');
        }

        $entity->setTradeOrderUuid($orderUuid)->setStoreUuid($store->getUuid())->setCounter($counter);
        unset($content['tradeOrderUuid'], $content['counterUuid']);
        return $content;
    }

    private function resolveCounter(mixed $uuid, string $storeUuid): Counter
    {
        if (!is_string($uuid) || !UUID::is_valid($uuid)) {
            throw new \InvalidArgumentException('counterUuid must be a valid UUID.');
        }
        $counter = $this->entityManager->getRepository(Counter::class)->findOneBy(['uuid' => $uuid]);
        if (!$counter instanceof Counter || !$counter->isEnabled() || $counter->getStoreUuid() !== $storeUuid) {
            throw new \InvalidArgumentException('Counter must be enabled and belong to this store.');
        }
        return $counter;
    }
}
