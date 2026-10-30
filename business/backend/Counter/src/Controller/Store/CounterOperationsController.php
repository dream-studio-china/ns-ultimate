<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Controller\Store;

use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Counter\Entity\Counter;
use NsUltimate\Business\Counter\Service\CounterSessionOperations;
use NsUltimate\Business\Store\Service\StaffCommandService;
use NsUltimate\Business\Store\Service\StaffOrderCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/store/{scopeId}/counter-operations', name: 'store-counter-operations-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class CounterOperationsController extends RestController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CounterSessionOperations $operations,
        private readonly StaffOrderCatalog $catalog,
        private readonly StaffCommandService $commands,
        private readonly LoggerInterface $logger,
    ) {}

    private function store(string $scopeId, string $action): Store
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store) throw new AccessDeniedException();
        $this->denyAccessUnlessGranted('store:counter_session:'.$action, $store);
        return $store;
    }

    private function respond(callable $action): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization'];
        try { return new JsonResponse(['code' => 0, 'data' => $action()], 200, $headers); }
        catch (AccessDeniedException $error) { throw $error; }
        catch (HttpException $error) { return new JsonResponse(['code' => $error->getStatusCode(), 'message' => $error->getMessage()], $error->getStatusCode(), $headers); }
        catch (\InvalidArgumentException) { return new JsonResponse(['code' => 422, 'message' => 'invalid_fields'], 422, $headers); }
        catch (\LogicException) { return new JsonResponse(['code' => 409, 'message' => 'invalid_transition'], 409, $headers); }
        catch (\Throwable $error) {
            $this->logger->error('Store counter session request failed.', ['exception' => $error]);
            return new JsonResponse(['code' => 500, 'message' => 'counter_operation_failed'], 500, $headers);
        }
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

    /** @return array{int, string} */
    private function query(Request $request): array
    {
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT);
        $q = $request->query->get('q', '');
        if (!$page || $page < 1 || $page > 100000 || !is_string($q) || mb_strlen($q) > 100) throw new HttpException(422, 'invalid_query');
        return [$page, trim($q)];
    }

    /** @param array<string, mixed> $view
     * @return array<string, mixed> */
    private function present(Store $store, array $view): array
    {
        return $this->isGranted('store:counter_order:read', $store) ? $view + ['ordersVisible' => true] : $this->operations->redact($view);
    }

    #[Route('/counters', name: 'counters', methods: ['GET'])]
    public function counters(Request $request, string $scopeId): JsonResponse
    {
        $store = $this->store($scopeId, 'read');
        return $this->respond(function () use ($request, $store): array {
            [$page, $q] = $this->query($request);
            $builder = $this->em->createQueryBuilder()->select('counter')->from(Counter::class, 'counter')->where('counter.storeUuid = :store')->setParameter('store', $store->getUuid());
            if ($q !== '') $builder->andWhere("(LOWER(counter.name) LIKE :q ESCAPE '!' OR LOWER(counter.code) LIKE :q ESCAPE '!')")->setParameter('q', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%');
            $count = clone $builder;
            $total = (int) $count->select('COUNT(counter.id)')->getQuery()->getSingleScalarResult();
            $counters = $builder->orderBy('counter.sort', 'ASC')->addOrderBy('counter.id', 'ASC')->setFirstResult(($page - 1) * 30)->setMaxResults(30)->getQuery()->getResult();
            return ['items' => array_map(fn(Counter $counter): array => $this->present($store, $this->operations->view($counter)), $counters), 'page' => $page, 'total' => $total, 'hasNext' => $page * 30 < $total];
        });
    }

    #[Route('/catalog', name: 'catalog', methods: ['GET'])]
    public function catalog(Request $request, string $scopeId): JsonResponse
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store || (!$this->isGranted('store:counter_order:create', $store) && !$this->isGranted('store:counter_order:replace', $store))) throw new AccessDeniedException();
        return $this->respond(function () use ($request, $store): array {
            [$page, $q] = $this->query($request);
            return $this->catalog->search($store, $page, $q);
        });
    }

    #[Route('/counters/{uuid}', name: 'detail', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function detail(string $scopeId, string $uuid): JsonResponse
    {
        $store = $this->store($scopeId, 'read');
        return $this->respond(fn(): array => $this->present($store, $this->operations->view($this->operations->counter($store, $uuid))));
    }

    #[Route('/counters/{uuid}/session/{action}', name: 'session', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}', 'action' => 'update|end'])]
    public function session(Request $request, string $scopeId, string $uuid, string $action): JsonResponse
    {
        $store = $this->store($scopeId, $action);
        return $this->respond(function () use ($request, $store, $uuid, $action): array {
            $actor = $this->getUser();
            if (!$actor instanceof User) throw new AccessDeniedException();
            $data = $this->body($request);
            return $this->commands->run($store, $actor->getUuid(), 'counter_session.'.$action.'.'.$uuid, $data,
                fn(): array => $this->operations->session($store, $uuid, $action, $data));
        });
    }

    #[Route('/counters/{uuid}/orders/{action}', name: 'orders', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}', 'action' => 'quote|create|replace|cancel'])]
    public function orders(Request $request, string $scopeId, string $uuid, string $action): JsonResponse
    {
        if ($action === 'quote') {
            $data = $this->body($request);
            $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
            if (!$store instanceof Store || !$this->isGranted(array_key_exists('replaceOrderUuid', $data) ? 'store:counter_order:replace' : 'store:counter_order:create', $store)) throw new AccessDeniedException();
            return $this->respond(function () use ($store, $uuid, $data): array {
                $connection = $this->em->getConnection();
                $connection->beginTransaction();
                try {
                    $this->em->refresh($store, LockMode::PESSIMISTIC_WRITE);
                    $quote = $this->operations->quote($store, $this->operations->counter($store, $uuid, true), $data);
                    $connection->commit();
                    return $quote;
                } catch (\Throwable $error) {
                    if ($connection->isTransactionActive()) $connection->rollBack();
                    $this->em->clear();
                    throw $error;
                }
            });
        }
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store || !$this->isGranted('store:counter_order:'.$action, $store)) throw new AccessDeniedException();
        return $this->respond(function () use ($request, $store, $uuid, $action): array {
            $actor = $this->getUser();
            if (!$actor instanceof User) throw new AccessDeniedException();
            $data = $this->body($request);
            return $this->commands->run($store, $actor->getUuid(), 'counter_order.'.$action.'.'.$uuid, $data,
                fn(): array => $action === 'cancel'
                    ? $this->operations->cancel($store, $uuid, $data)
                    : $this->operations->submit($store, $uuid, $data, $action === 'replace'));
        });
    }
}
