<?php

declare(strict_types=1);

namespace App\Settlement\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Settlement\Entity\SettlementAllocation;
use App\Settlement\Entity\SettlementPlan;
use App\Settlement\Service\SettlementPlanServiceInterface;
use App\Settlement\Service\SettlementServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/settlement-plans', name: 'manage-settlement-plans-')]
#[IsGranted('ROLE_ADMIN')]
final class SettlementPlanController extends RestController
{
    use ApiView;
    use DetailApiViewMixin;
    use ListApiViewMixin;

    public function __construct(
        protected readonly SettlementPlanServiceInterface $service,
        private readonly SettlementServiceInterface $settlementService,
    ) {
    }

    #[Route('/{uuid}/allocations/{allocationUuid}/post', name: 'post-allocation', methods: ['POST'])]
    public function postAllocationAction(string $uuid, string $allocationUuid): Response
    {
        $allocation = $this->allocationForPlan($uuid, $allocationUuid);
        if (!$allocation instanceof SettlementAllocation) {
            return $this->warning('Settlement allocation not found.', 404, '', 404);
        }

        try {
            $this->settlementService->postAllocation($allocation->getUuid());
            return $this->success('', 'Allocation posting requested');
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{uuid}/allocations/{allocationUuid}/reverse', name: 'reverse-allocation', methods: ['POST'])]
    public function reverseAllocationAction(string $uuid, string $allocationUuid, Request $request): Response
    {
        $allocation = $this->allocationForPlan($uuid, $allocationUuid);
        if (!$allocation instanceof SettlementAllocation) {
            return $this->warning('Settlement allocation not found.', 404, '', 404);
        }

        $content = json_decode($request->getContent(), true);
        $content = is_array($content) ? $content : [];
        foreach (['reversalId', 'reasonCode', 'reasonDetail'] as $field) {
            if (!is_string($content[$field] ?? null) || trim($content[$field]) === '') {
                return $this->warning("$field is required.", 400, '', 400);
            }
        }

        try {
            $actor = $this->getUser()?->getUserIdentifier() ?? 'system';
            $this->settlementService->reverseAllocation(
                $allocation->getUuid(),
                $content['reversalId'],
                $content['reasonCode'],
                $content['reasonDetail'],
                $actor,
            );
            return $this->success('', 'Allocation reversal requested');
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }

    private function allocationForPlan(string $planUuid, string $allocationUuid): ?SettlementAllocation
    {
        $plan = $this->service->get(['uuid' => $planUuid], false);
        if (!$plan instanceof SettlementPlan) {
            return null;
        }
        foreach ($plan->getAllocations() as $allocation) {
            if ($allocation->getUuid() === $allocationUuid) {
                return $allocation;
            }
        }

        return null;
    }
}
