<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Staff;

use App\Common\Service\MediaServiceInterface;
use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use App\Store\Entity\Product as CoreProduct;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Service\StoreOrderServiceInterface;
use App\Storage\Service\MediaStorageRegistry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Store\Entity\CatalogImage;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Business\Store\Entity\StaffOperation;
use NsUltimate\Business\Store\Service\StaffCatalogService;
use NsUltimate\Business\Store\Service\StaffCommandService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

/** Explicit DTOs and bounded queries; no administrator endpoints or arbitrary query language. */
#[Route('/store/{scopeId}/operations', name: 'store-operations-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class OperationsController extends RestController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StaffCatalogService $catalog,
        private readonly StaffCommandService $commands,
        private readonly StoreOrderServiceInterface $orders,
        private readonly MediaServiceInterface $media,
        private readonly MediaStorageRegistry $storage,
        private readonly LoggerInterface $logger,
        #[Target('state_machine.store_order')] private readonly WorkflowInterface $workflow,
    ) {}

    private function store(string $scopeId, string $permission): Store
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store) throw new AccessDeniedException();
        $this->denyAccessUnlessGranted($permission, $store);
        return $store;
    }

    private function actor(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw new AccessDeniedException();
        return $user->getUuid();
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        if (strlen($request->getContent()) > 65536) throw new HttpException(413, 'request_too_large');
        try { $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(422, 'invalid_json'); }
        if (!is_array($data) || array_is_list($data)) throw new HttpException(422, 'invalid_json');
        return $data;
    }

    private function respond(callable $action, int $status = 200): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization'];
        try { return new JsonResponse(['code' => 0, 'data' => $action()], $status, $headers); }
        catch (HttpException $error) { return new JsonResponse(['code' => $error->getStatusCode(), 'message' => $error->getMessage()], $error->getStatusCode(), $headers); }
        catch (AccessDeniedException $error) { throw $error; }
        catch (\InvalidArgumentException|\Symfony\Component\Validator\Exception\ValidatorException) { return new JsonResponse(['code' => 422, 'message' => 'invalid_fields'], 422, $headers); }
        catch (\LogicException) { return new JsonResponse(['code' => 409, 'message' => 'invalid_transition'], 409, $headers); }
        catch (\Throwable $error) {
            $this->logger->error('Store operational request failed.', ['exception' => $error]);
            return new JsonResponse(['code' => 500, 'message' => 'operation_failed'], 500, $headers);
        }
    }

    #[Route('/commands/{commandUuid}', name: 'command-result', methods: ['GET'], requirements: ['commandUuid' => '[0-9a-fA-F-]{36}'])]
    public function commandResult(string $scopeId, string $commandUuid): JsonResponse
    {
        $actor = $this->actor();
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store || !$this->em->getRepository(Membership::class)->findOneBy([
            'store' => $store, 'userUuid' => $actor, 'status' => Membership::STATUS_ACTIVE,
        ])) throw new AccessDeniedException();

        return $this->respond(function () use ($store, $actor, $commandUuid): array {
            $receipt = $this->em->getRepository(StaffOperation::class)->findOneBy([
                'storeUuid' => $store->getUuid(), 'actorUuid' => $actor, 'commandUuid' => $commandUuid,
            ]);
            // Absence can also mean the original transaction is still in flight.
            if (!$receipt instanceof StaffOperation) return ['status' => 'unknown'];
            $action = $receipt->summary()['action'];
            $permission = match (true) {
                $action === 'product.create' => 'store:product:create',
                $action === 'product.availability' => 'store:product:availability',
                $action === 'catalog_image.upload' => 'store:catalog_image:create',
                (bool) preg_match('/^product\.update\.[0-9a-fA-F-]{36}$/D', $action) => 'store:product:update',
                (bool) preg_match('/^order\.(accept|reject|fulfill|verify)\.[0-9a-fA-F-]{36}$/D', $action, $matches) => 'store:order:'.$matches[1],
                (bool) preg_match('/^counter_session\.(update|end)\.[0-9a-fA-F-]{36}$/D', $action, $matches) => 'store:counter_session:'.$matches[1],
                (bool) preg_match('/^counter_order\.(create|replace|cancel|merge)\.[0-9a-fA-F-]{36}$/D', $action, $matches) => 'store:counter_order:'.$matches[1],
                default => null,
            };
            if ($permission === null) throw new AccessDeniedException();
            $this->denyAccessUnlessGranted($permission, $store);
            $result = [];
            foreach (['uuid', 'counterUuid', 'sessionUuid', 'orderUuid', 'replacedOrderUuid'] as $key) {
                $value = $receipt->getResult()[$key] ?? null;
                if (is_string($value) && \App\Core\Utils\UUID::is_valid($value)) $result[$key] = $value;
            }
            return ['status' => 'succeeded', 'result' => $result];
        });
    }

    #[Route('/{resource}', name: 'list', methods: ['GET'], requirements: ['resource' => 'products|product-categories|counters|orders|history'])]
    public function list(Request $request, string $scopeId, string $resource): JsonResponse
    {
        $permissions = ['products' => 'product', 'product-categories' => 'product_category', 'counters' => 'counter', 'orders' => 'order', 'history' => 'operation'];
        $store = $this->store($scopeId, 'store:'.$permissions[$resource].':read');
        return $this->respond(function () use ($request, $store, $resource): array {
            $q = $request->query->get('q', '');
            $status = $request->query->get('status', 'all');
            if (!is_string($q) || mb_strlen($q) > 100 || !is_string($status)) throw new HttpException(422, 'invalid_query');
            $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT);
            $limit = filter_var($request->query->get('limit', '30'), FILTER_VALIDATE_INT);
            if (!$page || $page < 1 || $page > 100000 || !$limit || $limit < 1 || $limit > 100) throw new HttpException(422, 'invalid_pagination');
            $classes = ['products' => CoreProduct::class, 'product-categories' => ProductCategory::class, 'counters' => Counter::class, 'orders' => StoreOrder::class, 'history' => StaffOperation::class];
            $query = $this->em->createQueryBuilder()->select('item')->from($classes[$resource], 'item');
            if (in_array($resource, ['counters', 'history'], true)) $query->where('item.storeUuid = :store')->setParameter('store', $store->getUuid());
            else $query->where('item.store = :store')->setParameter('store', $store);
            if ($resource === 'products') {
                $query->andWhere('item.isDeleted = false')->leftJoin(Product::class, 'extension', 'WITH', 'extension.id = item.id')->orderBy('extension.sortOrder', 'ASC');
                if ($status !== 'all') {
                    if (!in_array($status, ['active', 'inactive', 'soldout'], true)) throw new HttpException(422, 'invalid_status');
                    $status === 'soldout' ? $query->andWhere('extension.isSoldout = true') : $query->andWhere('item.status = :status')->setParameter('status', $status);
                }
            } elseif ($resource === 'orders') {
                if ($status !== 'all') {
                    if (!in_array($status, ['pending_validation', 'awaiting_inventory', 'accepted', 'rejected', 'fulfillment_pending', 'fulfilling', 'fulfilled', 'verified', 'cancelled'], true)) throw new HttpException(422, 'invalid_status');
                    $query->andWhere('item.operationalStatus = :status')->setParameter('status', $status);
                }
                $query->orderBy('item.createdAt', 'DESC');
            } elseif (in_array($resource, ['product-categories', 'counters'], true)) {
                if ($status !== 'all') {
                    if (!in_array($status, ['enabled', 'disabled'], true)) throw new HttpException(422, 'invalid_status');
                    $query->andWhere('item.enabled = :enabled')->setParameter('enabled', $status === 'enabled');
                }
                $query->orderBy($resource === 'counters' ? 'item.sort' : 'item.sortOrder', 'ASC');
            } else $query->orderBy('item.createdAt', 'DESC');
            if (trim($q) !== '') {
                $fields = match ($resource) { 'products' => ['name', 'uuid'], 'product-categories' => ['name', 'slug'], 'counters' => ['name', 'code'], 'orders' => ['tradeOrderUuid', 'uuid'], default => ['actorUuid', 'action'] };
                $query->andWhere($query->expr()->orX(...array_map(static fn(string $field): string => "LOWER(item.$field) LIKE :query ESCAPE '!'", $fields)))
                    ->setParameter('query', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($q))).'%');
            }
            $count = clone $query;
            $total = (int) $count->select('COUNT(item.uuid)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
            $query->addOrderBy($resource === 'history' ? 'item.uuid' : 'item.id', 'ASC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
            $items = array_map(fn(object $item): array => $this->row($item, $store), $query->getQuery()->getResult());
            return ['items' => $items, 'page' => $page, 'hasNext' => $page * $limit < $total, 'total' => $total];
        });
    }

    /** @return array<string, mixed> */
    private function row(object $item, Store $store): array
    {
        if ($item instanceof CoreProduct) return $this->catalog->product($item);
        if ($item instanceof StoreOrder) return $this->order($item, $store);
        if ($item instanceof StaffOperation) return $item->summary();
        if ($item instanceof ProductCategory) return ['uuid' => $item->getUuid(), 'name' => $item->getName(), 'slug' => $item->getSlug(), 'sortOrder' => $item->getSortOrder(), 'enabled' => $item->isEnabled()];
        if ($item instanceof Counter) return ['uuid' => $item->getUuid(), 'name' => $item->getName(), 'code' => $item->getCode(), 'capacity' => $item->getCapacity(), 'sort' => $item->getSort(), 'enabled' => $item->isEnabled()];
        throw new \LogicException('Unsupported staff projection.');
    }

    #[Route('/products', name: 'product-create', methods: ['POST'])]
    public function createProduct(Request $request, string $scopeId): JsonResponse
    {
        $store = $this->store($scopeId, 'store:product:create');
        return $this->respond(function () use ($request, $store): array {
            $data = $this->body($request);
            return $this->commands->run($store, $this->actor(), 'product.create', $data, fn(): array => $this->catalog->save($store, null, $data));
        }, 201);
    }

    #[Route('/products/{uuid}', name: 'product-update', methods: ['PUT'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function updateProduct(Request $request, string $scopeId, string $uuid): JsonResponse
    {
        $store = $this->store($scopeId, 'store:product:update');
        return $this->respond(function () use ($request, $store, $uuid): array {
            $data = $this->body($request);
            return $this->commands->run($store, $this->actor(), 'product.update.'.$uuid, $data, fn(): array => $this->catalog->save($store, $uuid, $data));
        });
    }

    #[Route('/products/availability', name: 'availability', methods: ['POST'])]
    public function availability(Request $request, string $scopeId): JsonResponse
    {
        $store = $this->store($scopeId, 'store:product:availability');
        return $this->respond(function () use ($request, $store): array {
            $data = $this->body($request);
            return $this->commands->run($store, $this->actor(), 'product.availability', $data, fn(): array => $this->catalog->availability($store, $data));
        });
    }

    #[Route('/images', name: 'image-upload', methods: ['POST'])]
    public function uploadImage(Request $request, string $scopeId): JsonResponse
    {
        $store = $this->store($scopeId, 'store:catalog_image:create');
        return $this->respond(function () use ($request, $store): array {
            $file = $request->files->get('file');
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()
                || $file->getSize() <= 0 || $file->getSize() > 2 * 1024 * 1024
                || !in_array($file->getMimeType(), ['image/png', 'image/jpeg', 'image/webp'], true)) throw new HttpException(422, 'invalid_image');
            $dimensions = @getimagesize($file->getPathname());
            if ($dimensions === false || max($dimensions[0], $dimensions[1]) > 8192) throw new HttpException(422, 'invalid_image');
            $command = ['commandUuid' => $request->request->get('commandUuid'), 'digest' => hash_file('sha256', $file->getPathname())];
            $created = null;
            try {
                return $this->commands->run($store, $this->actor(), 'catalog_image.upload', $command, function () use ($store, $file, &$created): array {
                    // Store-owned media is deliberately not owned/deletable by an individual app user.
                    $created = $this->media->createFromUpload($file, 'local', [], null);
                    $this->em->persist(new CatalogImage($store->getUuid(), $created));
                    return ['before' => [], 'result' => ['uuid' => $created->getUuid(), 'url' => $created->getPath()]];
                });
            } catch (\Throwable $error) {
                if ($created !== null) {
                    try { $this->storage->get($created->getStorage())->delete($created->getPath()); }
                    catch (\Throwable) { $this->logger->warning('Rolled-back catalog image requires storage cleanup.'); }
                }
                throw $error;
            }
        }, 201);
    }

    #[Route('/orders/{uuid}', name: 'order-detail', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function orderDetail(string $scopeId, string $uuid): JsonResponse
    {
        $store = $this->store($scopeId, 'store:order:read');
        return $this->respond(fn(): array => $this->order($this->findOrder($store, $uuid), $store));
    }

    #[Route('/orders/{uuid}/{action}', name: 'order-command', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}', 'action' => 'accept|reject|fulfill|verify'])]
    public function orderCommand(Request $request, string $scopeId, string $uuid, string $action): JsonResponse
    {
        $store = $this->store($scopeId, 'store:order:'.$action);
        return $this->respond(function () use ($request, $store, $uuid, $action): array {
            $data = $this->body($request);
            if (array_diff(array_keys($data), ['commandUuid', 'version', 'reason'])) throw new HttpException(422, 'unknown_field');
            return $this->commands->run($store, $this->actor(), 'order.'.$action.'.'.$uuid, $data, function () use ($store, $uuid, $action, $data): array {
                $order = $this->findOrder($store, $uuid);
                $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
                $before = $this->order($order, $store);
                StaffCommandService::expectVersion($data, $before['version']);
                if (!in_array($action, $before['allowedActions'], true)) throw new HttpException(409, 'invalid_transition');
                if ($action === 'reject' && (!is_string($data['reason'] ?? null) || trim($data['reason']) === '' || mb_strlen($data['reason']) > 500)) throw new HttpException(422, 'reason_required');
                match ($action) {
                    'accept' => $this->orders->accept($order),
                    'reject' => $this->orders->reject($order, 'staff_rejected', trim($data['reason'])),
                    'fulfill' => $this->orders->fulfill($order),
                    'verify' => $this->orders->verify($order, $this->actor()),
                    default => throw new HttpException(409, 'invalid_transition'),
                };
                return ['before' => $before, 'result' => $this->order($order, $store)];
            });
        });
    }

    private function findOrder(Store $store, string $uuid): StoreOrder
    {
        $order = $this->em->getRepository(StoreOrder::class)->findOneBy(['store' => $store, 'uuid' => $uuid]);
        if (!$order instanceof StoreOrder) throw new HttpException(404, 'order_not_found');
        return $order;
    }

    /** @return array<string, mixed> */
    private function order(StoreOrder $order, Store $store): array
    {
        $actions = [];
        foreach (['accept', 'reject', 'fulfill', 'verify'] as $action) {
            if ($this->isGranted('store:order:'.$action, $store) && $this->workflow->can($order, $action)
                && ($action !== 'verify' || $order->isVerificationRequired())) $actions[] = $action;
        }
        // Operational DTOs never expose customer identity, delivery data or arbitrary metadata.
        $items = [];
        foreach (($order->getOrderSnapshot()['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $name = $item['productName'] ?? $item['productSnapshot']['name'] ?? null;
            $quantity = $item['quantity'] ?? null;
            if (is_string($name) && is_int($quantity) && $quantity > 0) $items[] = ['productName' => $name, 'quantity' => $quantity];
        }
        $state = ['uuid' => $order->getUuid(), 'tradeOrderUuid' => $order->getTradeOrderUuid(), 'operationalStatus' => $order->getOperationalStatus(),
            'totalAmount' => $order->getTotalAmount(), 'currency' => $order->getCurrency(), 'createdAt' => $order->getCreatedAt()->format(DATE_ATOM),
            'orderSnapshot' => ['items' => $items], 'verificationRequired' => $order->isVerificationRequired(), 'rejectionReason' => $order->getRejectionReason()];
        $state['version'] = StaffCommandService::version($state + ['updatedAt' => $order->getUpdatedAt()?->format(DATE_ATOM)]);
        return $state + ['allowedActions' => $actions];
    }
}
