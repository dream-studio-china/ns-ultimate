<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\App;

use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\View\ApiView;
use App\Core\View\ListApiViewMixin;
use App\Store\Service\StoreServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Service\OrderCounterService;
use NsUltimate\Business\Counter\Service\CounterOrderMergeService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/counters/{counterUuid}/orders', name: 'app-counter-orders-', requirements: ['counterUuid' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class OrderCounterController extends RestController
{
    use ApiView, ListApiViewMixin;

    public function __construct(
        protected readonly OrderCounterService $service,
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreServiceInterface $storeService,
        private readonly CounterOrderMergeService $mergeService,
    ) {
    }

    #[Route('/merge', name: 'merge', methods: ['POST'])]
    public function mergeAction(): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $filter = $this->commonFilter();
            $user = $this->getUser();
            if (!$user instanceof \App\Identity\Entity\User) {
                throw new \DomainException('Authenticated user is required.');
            }
            $order = $this->mergeService->merge($filter['counter'], $user);
            return $this->success([
                'uuid' => $order->getUuid(),
                'status' => $order->getStatus(),
                'totalAmount' => $order->getTotalAmount(),
                'currency' => $order->getCurrency(),
            ], 'Counter orders merged');
        } catch (\Throwable $error) {
            return $this->warning($error->getMessage(), 400, '', 400);
        }
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $counterUuid = $this->getRequestStack()->getCurrentRequest()?->attributes->get('counterUuid');
        $counter = is_string($counterUuid)
            ? $this->entityManager->getRepository(Counter::class)->findOneBy(['uuid' => $counterUuid])
            : null;
        if (!$counter instanceof Counter || !$counter->isEnabled()) {
            throw new NotFoundHttpException('Counter not found.');
        }

        $store = $this->storeService->findActiveByUuid($counter->getStoreUuid());
        if ($store === null) {
            throw new NotFoundHttpException('Counter not found.');
        }

        return [
            'counter' => $counter,
            'storeUuid' => $store->getUuid(),
            'storeTimezone' => $store->getTimezone(),
        ];
    }

    /**
     * @param array<string, mixed>|QueryBuilder|DqlExpression|null $filter
     */
    protected function listFilter(array|QueryBuilder|DqlExpression|null $filter = null): QueryBuilder
    {
        if (!is_array($filter) || !$filter['counter'] instanceof Counter || !is_string($filter['storeUuid'] ?? null) || !is_string($filter['storeTimezone'] ?? null)) {
            throw new \LogicException('Counter store context was not resolved.');
        }

        $timezone = new \DateTimeZone($filter['storeTimezone']);
        $startOfDay = new \DateTimeImmutable('today', $timezone);
        $startOfTomorrow = $startOfDay->modify('+1 day');
        $databaseTimezone = new \DateTimeZone(date_default_timezone_get());

        $orderQuery = $this->entityManager->createQueryBuilder()
            ->select('o.uuid')
            ->from(Order::class, 'o')
            ->where('o.uuid = oc.tradeOrderUuid')
            ->andWhere('o.createdAt >= :startOfDay')
            ->andWhere('o.createdAt < :startOfTomorrow')
            ->andWhere('o.status NOT IN (:closedStatuses)');

        return $this->entityManager->createQueryBuilder()
            ->select('oc')
            ->from(OrderCounter::class, 'oc')
            ->where('oc.counter = :counter')
            ->andWhere('oc.storeUuid = :storeUuid')
            ->andWhere($this->entityManager->createQueryBuilder()->expr()->exists($orderQuery->getDQL()))
            ->setParameter('counter', $filter['counter'])
            ->setParameter('storeUuid', $filter['storeUuid'])
            ->setParameter('startOfDay', $startOfDay->setTimezone($databaseTimezone))
            ->setParameter('startOfTomorrow', $startOfTomorrow->setTimezone($databaseTimezone))
            ->setParameter('closedStatuses', [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])
            ->orderBy('oc.createdAt', 'ASC');
    }

    /** @param mixed $entities
     * @return list<array{uuid: string, status: string, totalAmount: int, currency: string, createdAt: string, mergedIntoOrderUuid: ?string, items: list<array{productName: ?string, specificationName: ?string, quantity: int, unitPrice: int, price: int}>}> */
    protected function listProcessor(mixed $entities): array
    {
        if ($entities instanceof QueryBuilder) {
            $entities = $entities->getQuery()->getResult();
        }
        if (!is_iterable($entities)) {
            throw new \UnexpectedValueException('Counter order list must be iterable.');
        }

        $orderUuids = [];
        foreach ($entities as $binding) {
            if (!$binding instanceof OrderCounter) {
                throw new \UnexpectedValueException('Counter order list contains an unexpected entity.');
            }
            $orderUuids[] = $binding->getTradeOrderUuid();
        }
        if ($orderUuids === []) {
            return [];
        }

        $orders = $this->entityManager->getRepository(Order::class)->findBy(['uuid' => $orderUuids]);
        usort($orders, static fn (Order $left, Order $right): int => $left->getCreatedAt() <=> $right->getCreatedAt());

        return array_map(static function (Order $order): array {
            $targetUuid = ($order->getMetadata() ?? [])['mergedIntoOrderUuid'] ?? null;
            return [
                'uuid' => $order->getUuid(),
                'status' => $order->getStatus(),
                'totalAmount' => $order->getTotalAmount(),
                'currency' => $order->getCurrency(),
                'createdAt' => $order->getCreatedAt()->format(DATE_ATOM),
                'mergedIntoOrderUuid' => is_string($targetUuid) ? $targetUuid : null,
                'items' => array_values(array_map(static function (OrderItem $item): array {
                    $productSnapshot = $item->getProductSnapshot();

                    return [
                        'productName' => is_string($productSnapshot['name'] ?? null) ? $productSnapshot['name'] : null,
                        'specificationName' => $item->getSpecificationTitle(),
                        'quantity' => $item->getQuantity(),
                        'unitPrice' => $item->getUnitPrice(),
                        'price' => $item->getPrice(),
                    ];
                }, $order->getItems()->toArray())),
            ];
        }, $orders);
    }
}
