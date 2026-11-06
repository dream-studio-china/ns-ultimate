<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Staff;

use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Store\Entity\Store;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Service\OrderPaymentCloseService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class OrderPaymentCloseController extends RestController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly OrderPaymentCloseService $payments, private readonly LoggerInterface $logger) {}

    #[Route('/manage/orders/{tradeOrderUuid}/payment/close', name: 'manage-order-payment-close', methods: ['POST'], requirements: ['tradeOrderUuid' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(string $tradeOrderUuid, Request $request): JsonResponse
    {
        $scope = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $tradeOrderUuid]);
        $store = $scope instanceof OrderStoreLifecycle ? $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scope->getStoreUuid()]) : null;
        if (!$store instanceof Store) return $this->response(['code' => 404, 'message' => 'order_not_found'], 404);
        return $this->execute($store, $tradeOrderUuid, $request);
    }

    #[Route('/store/{scopeId}/trade-orders/{tradeOrderUuid}/payment/close', name: 'store-order-payment-close', methods: ['POST'], requirements: ['scopeId' => '[0-9a-fA-F-]{36}', 'tradeOrderUuid' => '[0-9a-fA-F-]{36}'])]
    public function staff(string $scopeId, string $tradeOrderUuid, Request $request): JsonResponse
    {
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => $scopeId]);
        if (!$store instanceof Store) return $this->response(['code' => 404, 'message' => 'order_not_found'], 404);
        $this->denyAccessUnlessGranted('store:order:payment_close', $store);
        return $this->execute($store, $tradeOrderUuid, $request);
    }

    private function execute(Store $store, string $orderUuid, Request $request): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 65536) throw new HttpException(413, 'request_too_large');
            try { $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new HttpException(400, 'invalid_json'); }
            if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['commandUuid', 'invoiceUuid', 'reason']) !== []
                || !is_string($data['commandUuid'] ?? null) || !UUID::is_valid($data['commandUuid'])
                || !is_string($data['invoiceUuid'] ?? null) || !UUID::is_valid($data['invoiceUuid'])
                || !is_string($data['reason'] ?? null) || trim($data['reason']) === '' || strlen($data['reason']) > 1000) throw new HttpException(400, 'invalid_fields');
            $actor = $this->getUser();
            if (!$actor instanceof User) throw new HttpException(401, 'authentication_required');
            $result = $this->payments->close($store, $orderUuid, $actor->getUuid(), ['commandUuid' => $data['commandUuid'], 'invoiceUuid' => $data['invoiceUuid'], 'reason' => trim($data['reason'])]);
            $status = $result['released'] ? 200 : 409;
            return $this->response(['code' => $status === 200 ? 0 : 409, 'message' => $status === 200 ? 'SUCCESS' : 'payment_already_paid', 'data' => $result], $status);
        } catch (HttpException $error) {
            return $this->response(['code' => $error->getStatusCode(), 'message' => $error->getMessage()], $error->getStatusCode());
        } catch (\Throwable $error) {
            $this->logger->error('Order payment closure failed.', ['tradeOrderUuid' => $orderUuid, 'exception' => $error]);
            return $this->response(['code' => 502, 'message' => 'payment_close_failed'], 502);
        }
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization']);
    }
}
