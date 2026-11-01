<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use NsUltimate\Business\HuifuFin\Service\HuifuRefundRecordService;
use NsUltimate\Business\HuifuFin\Service\Refund\HuifuRefundWorkflow;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-refunds', name: 'manage-huifu-refunds-')]
#[IsGranted('ROLE_ADMIN')]
final class HuifuRefundController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly HuifuRefundRecordService $service,
        private readonly HuifuRefundWorkflow $workflow,
    ) {
    }

    #[Route('/{id<\d+>}/refresh', name: 'refresh', methods: ['POST'])]
    public function refreshAction(int $id): Response
    {
        $refund = $this->service->get(['id' => $id]);
        if (!$refund instanceof HuifuRefund) {
            return $this->warning('Huifu refund not found.', 404, '', 404);
        }
        try {
            return $this->success($this->workflow->refresh($refund), 'Huifu refund refreshed');
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }
}
