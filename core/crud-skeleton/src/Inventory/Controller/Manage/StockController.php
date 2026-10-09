<?php

declare(strict_types=1);

namespace App\Inventory\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\ListApiViewMixin;
use App\Inventory\Service\InventoryServiceInterface;
use App\Inventory\Service\StockServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/inventory/stocks', name: 'manage-inventory-stocks-')]
#[IsGranted('ROLE_ADMIN')]
final class StockController extends RestController
{
    use ApiView, ListApiViewMixin;

    public function __construct(
        protected readonly StockServiceInterface $service,
        private readonly InventoryServiceInterface $inventory,
    ) {
    }

    #[Route('/{storeUuid}/{materialUuid}', name: 'detail', methods: ['GET'])]
    public function detailAction(string $storeUuid, string $materialUuid): Response
    {
        try {
            return $this->success($this->inventory->getStockView($storeUuid, $materialUuid));
        } catch (\InvalidArgumentException $exception) {
            return $this->warning($exception->getMessage(), 404, '', 404);
        }
    }

    #[Route('/{storeUuid}/{materialUuid}/adjust', name: 'adjust', methods: ['POST'])]
    public function adjustAction(Request $request, string $storeUuid, string $materialUuid): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_string($data['quantityDelta'] ?? null) || !is_string($data['reason'] ?? null)) {
            return $this->warning('quantityDelta and reason are required.', 400, '', 400);
        }
        try {
            $stock = $this->inventory->adjustStock(
                $storeUuid,
                $materialUuid,
                $data['quantityDelta'],
                $data['reason'],
                is_string($data['referenceId'] ?? null) ? $data['referenceId'] : null,
                null,
                is_bool($data['allowNegativeStock'] ?? null) ? $data['allowNegativeStock'] : null,
            );

            return $this->success($stock);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{storeUuid}/{materialUuid}/policy', name: 'policy', methods: ['PUT'])]
    public function policyAction(Request $request, string $storeUuid, string $materialUuid): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_bool($data['allowNegativeStock'] ?? null)) {
            return $this->warning('allowNegativeStock must be a boolean.', 400, '', 400);
        }
        try {
            return $this->success($this->inventory->setStockAllowNegative(
                $storeUuid,
                $materialUuid,
                $data['allowNegativeStock'],
            ));
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }
    }
}
