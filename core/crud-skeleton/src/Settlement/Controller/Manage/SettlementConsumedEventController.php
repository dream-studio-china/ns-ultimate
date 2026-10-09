<?php

declare(strict_types=1);

namespace App\Settlement\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Settlement\Service\SettlementConsumedEventServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/settlement-consumed-events', name: 'manage-settlement-consumed-events-')]
#[IsGranted('ROLE_ADMIN')]
final class SettlementConsumedEventController extends RestController
{
    use ApiView;
    use DetailApiViewMixin;
    use ListApiViewMixin;

    public function __construct(protected readonly SettlementConsumedEventServiceInterface $service)
    {
    }
}
