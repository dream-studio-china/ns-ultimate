<?php

declare(strict_types=1);

namespace App\Settlement\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Settlement\Service\SettlementAllocationServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/settlement-allocations', name: 'manage-settlement-allocations-')]
#[IsGranted('ROLE_ADMIN')]
final class SettlementAllocationController extends RestController
{
    use ApiView;
    use DetailApiViewMixin;
    use ListApiViewMixin;

    public function __construct(protected readonly SettlementAllocationServiceInterface $service)
    {
    }
}
