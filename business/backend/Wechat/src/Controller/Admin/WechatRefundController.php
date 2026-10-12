<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Payment\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatRefundAttempt;
use NsUltimate\Business\Wechat\Service\WechatRefundAttemptService;
use NsUltimate\Business\Wechat\Service\WechatRefundOrchestrator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administrator visibility into refund attempts plus refund requests.
 *
 * Refunds are created through the orchestrator (which reserves pending amounts
 * and only applies accounting after a verified provider success), not through
 * generic CRUD. This controller is read-only for stored attempts; new refunds
 * use the explicit request action.
 */
#[Route('/manage/wechat-refunds', name: 'manage-wechat-refunds-')]
#[IsGranted('ROLE_ADMIN')]
final class WechatRefundController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly WechatRefundAttemptService $service,
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatRefundOrchestrator $orchestrator,
    ) {
    }

    #[Route('/request', name: 'request', methods: ['POST'])]
    public function requestAction(Request $request): Response
    {
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

        return $this->success($refund, 'SUCCESS', 201);
    }
}
