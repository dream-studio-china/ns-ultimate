<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Identity\Entity\User;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Service\OrderRefundService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Refunds the linked Invoice of a Trade order, not the StoreOrder fulfillment aggregate. */
#[Route('/store/{scopeId}/trade-orders/{tradeOrderUuid}', name: 'store-trade-order-refund-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}', 'tradeOrderUuid' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class OrderRefundController extends RestController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly OrderRefundService $refunds) {}

    private function authorize(string $scopeId): void
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store) throw new HttpException(404, 'order_not_found');
        if ($this->isGranted('ROLE_ADMIN')) return;
        $this->denyAccessUnlessGranted('store:order:refund', $store);
    }

    /** @param array<string, mixed> $data */
    private function result(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization']);
    }

    #[Route('/refund', name: 'create', methods: ['POST'])]
    public function createAction(string $scopeId, string $tradeOrderUuid, Request $request): JsonResponse
    {
        $this->authorize($scopeId);
        try {
            $this->refunds->order($scopeId, $tradeOrderUuid);
            if (strlen($request->getContent()) > 65536) throw new HttpException(413, 'request_too_large');
            try { $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new HttpException(400, 'invalid_json'); }
            if (!is_array($body) || array_is_list($body) || array_diff(array_keys($body), ['amount', 'reason', 'idempotencyKey']) !== []) throw new HttpException(400, 'invalid_fields');
            $amount = $body['amount'] ?? null;
            $reason = $body['reason'] ?? null;
            $key = $body['idempotencyKey'] ?? null;
            if (!is_int($amount) || $amount <= 0 || !is_string($reason) || trim($reason) === '' || strlen($reason) > 1000
                || !is_string($key) || trim($key) === '' || strlen($key) > 200) throw new HttpException(400, 'invalid_fields');
            $actor = $this->getUser();
            if (!$actor instanceof User) throw new HttpException(401, 'authentication_required');
            $refund = $this->refunds->request($scopeId, $tradeOrderUuid, $actor->getUuid(), $amount, trim($reason), $key);
            $data = $this->refunds->summary($refund);
            return $this->result(['code' => 0, 'data' => $data], in_array($data['status'], ['processing', 'unknown'], true) ? 202 : 200);
        } catch (HttpException $error) {
            return $this->result(['code' => $error->getStatusCode(), 'message' => $error->getMessage()], $error->getStatusCode());
        }
    }

    #[Route('/refunds/{refundUuid}', name: 'detail', methods: ['GET'], requirements: ['refundUuid' => '[0-9a-fA-F-]{36}'])]
    public function detailAction(string $scopeId, string $tradeOrderUuid, string $refundUuid): JsonResponse
    {
        $this->authorize($scopeId);
        try {
            $refund = $this->refunds->find($scopeId, $tradeOrderUuid, $refundUuid);
            return $this->result(['code' => 0, 'data' => $this->refunds->summary($refund)]);
        } catch (HttpException $error) {
            return $this->result(['code' => $error->getStatusCode(), 'message' => $error->getMessage()], $error->getStatusCode());
        }
    }
}
