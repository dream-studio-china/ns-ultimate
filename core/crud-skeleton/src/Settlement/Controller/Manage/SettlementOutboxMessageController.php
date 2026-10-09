<?php

declare(strict_types=1);

namespace App\Settlement\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Settlement\Service\SettlementOutboxMessageServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/settlement-outbox-messages', name: 'manage-settlement-outbox-messages-')]
#[IsGranted('ROLE_ADMIN')]
final class SettlementOutboxMessageController extends RestController
{
    use ApiView;
    use DetailApiViewMixin;
    use ListApiViewMixin;

    public function __construct(protected readonly SettlementOutboxMessageServiceInterface $service)
    {
    }
}
