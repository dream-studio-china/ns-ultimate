<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Store;

use App\Core\Controller\RestController;
use App\Core\Query\DqlExpression;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Payment\Entity\Invoice;
use App\Store\Entity\Store;
use App\Store\Service\StoreServiceInterface;
use App\Store\View\StoreScopedAuthorizationApiMixin;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use NsUltimate\Business\Wechat\Entity\WechatRefundAttempt;
use NsUltimate\Business\Wechat\Service\WechatRefundAttemptService;
use NsUltimate\Business\Wechat\Service\WechatRefundOrchestrator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Store-scoped visibility into refund attempts plus refund requests.
 *
 * The Store is resolved from the scope route parameter, never from request
 * data. Refund requests require the store:wechat:refund permission.
 */
#[Route('/store/{scopeId}/wechat-refunds', name: 'store-wechat-refunds-', requirements: ['scopeId' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class WechatRefundController extends RestController
{
    use StoreScopedAuthorizationApiMixin, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly WechatRefundAttemptService $service,
        private readonly StoreServiceInterface $storeService,
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatRefundOrchestrator $orchestrator,
    ) {
    }

    /** @return array<string, mixed>|QueryBuilder|DqlExpression */
    protected function storeScopedFilter(Store $store): array|QueryBuilder|DqlExpression
    {
        return $this->entityManager->createQueryBuilder()
            ->select('entity')
            ->from(WechatRefundAttempt::class, 'entity')
            ->innerJoin('entity.paymentAttempt', 'attempt')
            ->andWhere('attempt.storeUuid = :storeUuid')
            ->setParameter('storeUuid', $store->getUuid());
    }

    protected function storeService(): StoreServiceInterface
    {
        return $this->storeService;
    }

    protected function storeAuthorizationResource(): string
    {
        return 'wechat';
    }

    /** @return array<string, string> */
    protected function storeActionPermissions(): array
    {
        return ['create' => 'store:wechat:refund'];
    }

    #[Route('/request', name: 'request', methods: ['POST'])]
    public function requestAction(Request $request): Response
    {
        $store = $this->storeForAuthorization();
        $this->denyAccessUnlessGranted('store:wechat:refund', $store);

        $content = $request->getContent();
        try {
            $data = $content === '' ? [] : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->warning('Request body must be valid JSON.', 400, '', 400);
        }
        if (!is_array($data)) {
            return $this->warning('Request body must be a JSON object.', 400, '', 400);
        }

        $invoiceUuid = $data['invoiceUuid'] ?? null;
        $amount = $data['amount'] ?? null;
        if (!is_string($invoiceUuid) || $invoiceUuid === '' || !is_int($amount)) {
            return $this->warning('invoiceUuid and integer amount are required.', 400, '', 400);
        }

        $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['uuid' => $invoiceUuid]);
        if (!$invoice instanceof Invoice) {
            return $this->warning('Invoice was not found.', 404, '', 404);
        }

        try {
            $refund = $this->orchestrator->requestRefund(
                $invoice,
                $amount,
                (string) ($data['reason'] ?? 'Refund'),
                ['idempotencyKey' => $data['idempotencyKey'] ?? $invoiceUuid.':'.$amount],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 500, '', 500);
        }

        if ($refund->getPaymentAttempt()?->getStoreUuid() !== $store->getUuid()) {
            return $this->warning('Invoice does not belong to this store.', 403, '', 403);
        }

        return $this->success($refund, 'SUCCESS', 201);
    }
}
