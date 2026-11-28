<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use NsUltimate\Business\Counter\Entity\OrderMerge;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AdminCounterOrders
{
    private const PAGE_SIZE = 24;
    private const GRAPH_LIMIT = 200;

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function stores(InputBag $input): array
    {
        $query = $this->em->createQueryBuilder()->select('s.uuid', 's.name', 's.code')->from(Store::class, 's')
            ->orderBy('s.name', 'ASC')->addOrderBy('s.id', 'ASC');
        $this->search($query, $this->text($input, 'q'), ['s.name', 's.uuid'], 'storeSearch');

        return $this->page($query, $input);
    }

    public function counters(InputBag $input): array
    {
        $query = $this->em->createQueryBuilder()->select('c.uuid', 'c.name', 'c.code', 'c.enabled', 'c.storeUuid', 's.name AS storeName')
            ->from(Counter::class, 'c')->leftJoin(Store::class, 's', 'WITH', 's.uuid = c.storeUuid')
            ->orderBy('s.name', 'ASC')->addOrderBy('c.sort', 'ASC')->addOrderBy('c.code', 'ASC')->addOrderBy('c.id', 'ASC');
        $this->search($query, $this->text($input, 'counter'), ['c.name', 'c.code', 'c.uuid'], 'counterSearch');
        $store = $this->uuid($input, 'store');
        if ($store !== '') {
            $query->andWhere('c.storeUuid = :store')->setParameter('store', $store);
        }

        $matching = $this->boundOrders()->select('o.id')
            ->andWhere('b.counter = c.id AND b.storeUuid = c.storeUuid');
        $unfiltered = $matching->getDQL();
        $this->filterOrders($matching, $input);
        if ($matching->getDQL() !== $unfiltered) {
            $query->andWhere('EXISTS ('.$matching->getDQL().')');
            foreach ($matching->getParameters() as $parameter) {
                $query->setParameter($parameter->getName(), $parameter->getValue(), $parameter->getType());
            }
        }

        return $this->page($query, $input);
    }

    public function history(string $counterUuid, InputBag $input): array
    {
        $counter = $this->counter($counterUuid);
        $query = $this->boundOrders()->select('o.id', 'o.uuid', 'o.status', 'o.createdAt', 'o.totalAmount', 'o.currency',
            'session.uuid AS sessionUuid', 'session.startedAt AS sessionStartedAt')
            ->leftJoin('b.counterSession', 'session', 'WITH', 'session.counter = b.counter')
            ->andWhere('b.counter = :counter AND b.storeUuid = :boundStore')
            ->setParameter('counter', $counter->getId())->setParameter('boundStore', $counter->getStoreUuid())
            ->orderBy('o.createdAt', 'DESC')->addOrderBy('o.id', 'DESC');
        $this->filterOrders($query, $input);
        $page = $this->page($query, $input);
        $page['items'] = array_map($this->summary(...), $page['items']);
        if ($input->getInt('page', 1) === 1 && $page['items'] !== []) {
            $first = array_slice($page['items'], 0, 3, true);
            $previews = array_column($this->receipts(array_column($first, 'uuid')), null, 'uuid');
            foreach ($first as $index => $row) {
                $page['items'][$index] = [...($previews[$row['uuid']] ?? []), ...$row];
            }
        }

        return $page;
    }

    public function graph(string $counterUuid, string $orderUuid): array
    {
        $orderUuid = strtolower($orderUuid);
        $counter = $this->counter($counterUuid);
        $root = $this->boundOrders()->select('o.uuid', 'session.id AS sessionId')
            ->leftJoin('b.counterSession', 'session', 'WITH', 'session.counter = b.counter')
            ->andWhere('b.counter = :counter AND b.storeUuid = :store AND o.uuid = :order')
            ->setParameter('counter', $counter->getId())->setParameter('store', $counter->getStoreUuid())
            ->setParameter('order', $orderUuid)->getQuery()->getOneOrNullResult();
        if ($root === null) {
            throw new NotFoundHttpException('Order is not bound to this counter.');
        }

        $seen = [$orderUuid => true];
        $truncated = false;
        if ($root['sessionId'] !== null) {
            $sessionOrders = $this->boundOrders()->select('o.uuid')
                ->andWhere('b.counter = :counter AND b.storeUuid = :store AND b.counterSession = :session AND o.uuid <> :order')
                ->setParameter('counter', $counter->getId())->setParameter('store', $counter->getStoreUuid())
                ->setParameter('session', $root['sessionId'])->setParameter('order', $orderUuid)
                ->orderBy('o.createdAt', 'DESC')->addOrderBy('o.id', 'DESC')->setMaxResults(self::GRAPH_LIMIT)
                ->getQuery()->getSingleColumnResult();
            $truncated = count($sessionOrders) >= self::GRAPH_LIMIT;
            foreach (array_slice($sessionOrders, 0, self::GRAPH_LIMIT - 1) as $uuid) {
                $seen[$uuid] = true;
            }
        }
        $frontier = array_keys($seen);
        $edges = [];
        while ($frontier !== []) {
            $links = $this->em->createQueryBuilder()->select('m.sourceOrderUuid AS source', 'm.targetOrderUuid AS target')
                ->from(OrderMerge::class, 'm')
                ->innerJoin(OrderStoreLifecycle::class, 'sourceScope', 'WITH', 'sourceScope.tradeOrderUuid = m.sourceOrderUuid AND sourceScope.storeUuid = :store')
                ->innerJoin(OrderStoreLifecycle::class, 'targetScope', 'WITH', 'targetScope.tradeOrderUuid = m.targetOrderUuid AND targetScope.storeUuid = :store')
                ->innerJoin(Order::class, 'sourceOrder', 'WITH', 'sourceOrder.uuid = m.sourceOrderUuid')
                ->innerJoin(Order::class, 'targetOrder', 'WITH', 'targetOrder.uuid = m.targetOrderUuid')
                ->where('m.sourceOrderUuid IN (:frontier) OR m.targetOrderUuid IN (:frontier)')
                ->setParameter('store', $counter->getStoreUuid())->setParameter('frontier', $frontier)
                ->orderBy('m.sourceOrderUuid', 'ASC')->setMaxResults(self::GRAPH_LIMIT * 2 + 1)->getQuery()->getArrayResult();
            if (count($links) > self::GRAPH_LIMIT * 2) {
                $truncated = true;
            }
            $frontier = [];
            foreach ($links as $link) {
                foreach ([$link['source'], $link['target']] as $uuid) {
                    if (isset($seen[$uuid])) {
                        continue;
                    }
                    if (count($seen) >= self::GRAPH_LIMIT) {
                        $truncated = true;
                        continue 2;
                    }
                    $seen[$uuid] = true;
                    $frontier[] = $uuid;
                }
                $edges[$link['source']] = $link;
            }
        }

        $nodes = $this->receipts(array_keys($seen));
        $existing = array_fill_keys(array_column($nodes, 'uuid'), true);
        if (!isset($existing[$orderUuid])) {
            throw new NotFoundHttpException('Order no longer exists.');
        }

        return ['nodes' => $nodes, 'edges' => array_values(array_filter($edges, static fn ($edge) =>
            isset($existing[$edge['source']], $existing[$edge['target']]))), 'truncated' => $truncated];
    }

    private function receipts(array $uuids): array
    {
        $orders = $this->em->createQueryBuilder()->select('o', 'item')->from(Order::class, 'o')
            ->leftJoin('o.items', 'item')->where('o.uuid IN (:orders)')->setParameter('orders', $uuids)
            ->orderBy('o.createdAt', 'DESC')->addOrderBy('o.id', 'DESC')->addOrderBy('item.id', 'ASC')->getQuery()->getResult();
        $nodes = [];
        foreach ($orders as $order) {
            $items = $order->getItems();
            $nodes[] = [
                ...$this->summary(['id' => $order->getId(), 'uuid' => $order->getUuid(), 'status' => $order->getStatus(),
                    'createdAt' => $order->getCreatedAt(), 'totalAmount' => $order->getTotalAmount(), 'currency' => $order->getCurrency()]),
                'itemCount' => count($items),
                'items' => array_map(static function ($item): array {
                    $name = $item->getProductSnapshot()['name'] ?? null;
                    return ['name' => is_string($name) && $name !== '' ? $name : $item->getSpecificationTitle(),
                        'quantity' => $item->getQuantity()];
                }, $items->slice(0, 12)),
            ];
        }

        return $nodes;
    }

    private function boundOrders(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->from(OrderCounter::class, 'b')
            ->innerJoin(Order::class, 'o', 'WITH', 'o.uuid = b.tradeOrderUuid')
            ->leftJoin('o.user', 'u')
            ->leftJoin(OrderStoreLifecycle::class, 'l', 'WITH', 'l.tradeOrderUuid = o.uuid AND l.storeUuid = b.storeUuid')
            ->leftJoin(Store::class, 'boundStoreEntity', 'WITH', 'boundStoreEntity.uuid = b.storeUuid')
            ->leftJoin(StoreOrder::class, 'so', 'WITH', 'so.tradeOrderUuid = o.uuid AND so.store = boundStoreEntity.id')
            ->leftJoin(OrderMerge::class, 'm', 'WITH', 'm.sourceOrderUuid = o.uuid')
            ->where('l.id IS NOT NULL OR NOT EXISTS (SELECT other.id FROM '.OrderStoreLifecycle::class.' other WHERE other.tradeOrderUuid = o.uuid)');
    }

    private function filterOrders(QueryBuilder $query, InputBag $input): void
    {
        $order = $this->text($input, 'order');
        if ($order !== '') {
            if (UUID::is_valid($order)) {
                $query->andWhere('(o.uuid = :order OR so.uuid = :order)')->setParameter('order', strtolower($order));
            } elseif (preg_match('/^#?[0-9]{1,10}$/D', $order)) {
                $query->andWhere('o.id = :order')->setParameter('order', (int) ltrim($order, '#'));
            } else {
                throw new BadRequestHttpException('Order must be a UUID or numeric ID.');
            }
        }
        if (($user = $this->uuid($input, 'user')) !== '') {
            $query->andWhere('u.uuid = :user')->setParameter('user', $user);
        }
        foreach (['status' => 'o.status', 'payment' => 'o.paymentStatus', 'operational' => 'so.operationalStatus'] as $key => $field) {
            $value = $this->text($input, $key);
            if ($value !== '') {
                if (!preg_match('/^[a-z_]+(,[a-z_]+)*$/D', $value)) {
                    throw new BadRequestHttpException('Invalid order status filter.');
                }
                $query->andWhere($field.' IN (:'.$key.')')->setParameter($key, explode(',', $value));
            }
        }
        foreach (['from' => '>=', 'before' => '<'] as $key => $operator) {
            $value = $this->text($input, $key);
            if ($value === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
                throw new BadRequestHttpException('Invalid UTC date filter.');
            }
            $query->andWhere('o.createdAt '.$operator.' :date_'.$key)
                ->setParameter('date_'.$key, $date->setTimezone(new \DateTimeZone(date_default_timezone_get())), 'datetime_immutable');
        }
        foreach (['anonymous' => 'o.user IS NULL', 'merged' => '(m.id IS NOT NULL OR EXISTS (SELECT incoming.id FROM '.OrderMerge::class.' incoming WHERE incoming.targetOrderUuid = o.uuid))'] as $key => $condition) {
            $value = $this->text($input, $key);
            if ($value === '') {
                continue;
            }
            if (!in_array($value, ['yes', 'no'], true)) {
                throw new BadRequestHttpException('Invalid order flag filter.');
            }
            $query->andWhere($key === 'anonymous'
                ? ($value === 'no' ? 'o.user IS NOT NULL' : $condition)
                : ($value === 'no' ? 'NOT ' : '').$condition);
        }
    }

    private function search(QueryBuilder $query, string $value, array $fields, string $parameter): void
    {
        if ($value === '') {
            return;
        }
        $conditions = array_map(static fn (string $field) => "LOWER($field) LIKE :$parameter ESCAPE '!'", $fields);
        $query->andWhere('('.implode(' OR ', $conditions).')')
            ->setParameter($parameter, '%'.strtr(mb_strtolower($value), ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
    }

    private function counter(string $uuid): Counter
    {
        return $this->em->getRepository(Counter::class)->findOneBy(['uuid' => strtolower($uuid)])
            ?? throw new NotFoundHttpException('Counter not found.');
    }

    private function page(QueryBuilder $query, InputBag $input): array
    {
        $page = $input->getInt('page', 1);
        if ($page < 1 || $page > 1000000) {
            throw new BadRequestHttpException('Invalid page.');
        }
        $rows = $query->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE + 1)->getQuery()->getArrayResult();

        return ['items' => array_slice($rows, 0, self::PAGE_SIZE), 'hasNext' => count($rows) > self::PAGE_SIZE];
    }

    private function summary(array $row): array
    {
        $row['createdAt'] = $row['createdAt']->format(DATE_ATOM);
        if (array_key_exists('sessionStartedAt', $row)) {
            $row['sessionStartedAt'] = $row['sessionStartedAt']?->format(DATE_ATOM);
        }
        $row['totalAmount'] = (int) $row['totalAmount'];

        return $row;
    }

    private function text(InputBag $input, string $key): string
    {
        $value = trim((string) $input->get($key, ''));
        if (mb_strlen($value) > 255) {
            throw new BadRequestHttpException('Filter is too long.');
        }

        return $value;
    }

    private function uuid(InputBag $input, string $key): string
    {
        $value = $this->text($input, $key);
        if ($value !== '' && !UUID::is_valid($value)) {
            throw new BadRequestHttpException('Invalid UUID filter.');
        }

        return strtolower($value);
    }
}
