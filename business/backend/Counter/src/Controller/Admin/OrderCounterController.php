<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Service\OrderCounterService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/order-counters', name: 'manage-order-counters-')]
#[IsGranted('ROLE_ADMIN')]
final class OrderCounterController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['tradeOrderUuid', 'counterUuid'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['tradeOrderUuid', 'counterUuid'];

    public function __construct(
        protected readonly OrderCounterService $service,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed> */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof OrderCounter) {
            throw new \LogicException('Unexpected order counter entity.');
        }
        $orderUuid = $content['tradeOrderUuid'] ?? null;
        if (!is_string($orderUuid) || !UUID::is_valid($orderUuid)) {
            throw new \InvalidArgumentException('tradeOrderUuid must be a valid UUID.');
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        $storeUuid = $this->orderStoreUuid($order);
        $counter = $this->resolveCounter($content['counterUuid'] ?? null, $storeUuid);
        $entity->setTradeOrderUuid($orderUuid)->setStoreUuid($storeUuid)->setCounter($counter);
        unset($content['tradeOrderUuid'], $content['counterUuid']);
        return $content;
    }

    private function orderStoreUuid(?object $order): string
    {
        if (!$order instanceof Order) {
            throw new \InvalidArgumentException('Trade order was not found.');
        }
        $storeUuid = $this->entityManager->getRepository(OrderStoreLifecycle::class)
            ->findOneBy(['tradeOrderUuid' => $order->getUuid()])
            ?->getStoreUuid();
        if (!is_string($storeUuid) || !UUID::is_valid($storeUuid)) {
            throw new \InvalidArgumentException('Order must have a trusted store lifecycle.');
        }
        return $storeUuid;
    }

    private function resolveCounter(mixed $uuid, string $storeUuid): Counter
    {
        if (!is_string($uuid) || !UUID::is_valid($uuid)) {
            throw new \InvalidArgumentException('counterUuid must be a valid UUID.');
        }
        $counter = $this->entityManager->getRepository(Counter::class)->findOneBy(['uuid' => $uuid]);
        if (!$counter instanceof Counter || !$counter->isEnabled() || $counter->getStoreUuid() !== $storeUuid) {
            throw new \InvalidArgumentException('Counter must be enabled and belong to the order store.');
        }
        return $counter;
    }
}
