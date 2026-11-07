<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuReconciliationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-refunds/{huifuRefundUuid}', name: 'manage-huifu-refund-reconciliation-', requirements: ['huifuRefundUuid' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
final class HuifuRefundReconciliationController extends RestController
{
    private const RECONCILABLE_STATUSES = [
        HuifuRefund::STATUS_SUBMITTED,
        HuifuRefund::STATUS_PROCESSING,
        HuifuRefund::STATUS_SUCCEEDED,
        HuifuRefund::STATUS_UNKNOWN,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuReconciliationService $reconciliation,
    ) {
    }

    #[Route('/reconcile', name: 'reconcile', methods: ['POST'])]
    public function reconcileAction(string $huifuRefundUuid): JsonResponse
    {
        $refund = $this->entityManager->getRepository(HuifuRefund::class)
            ->findOneBy(['uuid' => strtolower($huifuRefundUuid)]);
        if (!$refund instanceof HuifuRefund) {
            return $this->failure('huifu_refund_not_found', 404);
        }
        if (!in_array($refund->getStatus(), self::RECONCILABLE_STATUSES, true)) {
            return $this->failure('huifu_refund_not_reconcilable', 409);
        }

        try {
            $refund = $this->reconciliation->queryRefund($refund);
        } catch (\Throwable) {
            $this->refresh($refund);

            return $this->failure('huifu_reconciliation_failed', 502, $refund);
        }

        return $this->successResponse($refund);
    }

    private function successResponse(HuifuRefund $refund): JsonResponse
    {
        $unresolved = in_array($refund->getStatus(), [HuifuRefund::STATUS_PROCESSING, HuifuRefund::STATUS_UNKNOWN], true);

        return $this->result(['code' => 0, 'data' => $this->summary($refund)], $unresolved ? 202 : 200);
    }

    private function failure(string $message, int $status, ?HuifuRefund $refund = null): JsonResponse
    {
        $payload = ['code' => $status, 'message' => $message];
        if ($refund instanceof HuifuRefund) {
            $payload['data'] = $this->summary($refund);
        }

        return $this->result($payload, $status);
    }

    /** @return array<string, mixed> */
    private function summary(HuifuRefund $refund): array
    {
        return [
            'uuid' => $refund->getUuid(),
            'invoiceUuid' => $refund->getInvoiceUuid(),
            'attemptUuid' => $refund->getAttemptUuid(),
            'storeUuid' => $refund->getAttempt()?->getStoreUuid(),
            'amount' => $refund->getAmount(),
            'confirmedAmount' => $refund->getConfirmedAmount(),
            'status' => $refund->getStatus(),
            'coreApplied' => $refund->isCoreApplied(),
            'lastQueriedAt' => $refund->getLastQueriedAt()?->format(\DateTimeInterface::ATOM),
            'nextQueryAt' => $refund->getNextQueryAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function refresh(HuifuRefund $refund): void
    {
        try {
            $this->entityManager->refresh($refund);
        } catch (\Throwable) {
            // Return the last known status if the entity can no longer be refreshed.
        }
    }

    /** @param array<string, mixed> $payload */
    private function result(array $payload, int $status): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization']);
    }
}
