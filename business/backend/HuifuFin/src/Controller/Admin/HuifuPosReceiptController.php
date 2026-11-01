<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use NsUltimate\Business\HuifuFin\Service\HuifuPosReceiptService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-pos-receipts', name: 'manage-huifu-pos-receipts-')]
#[IsGranted('ROLE_ADMIN')]
final class HuifuPosReceiptController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(protected readonly HuifuPosReceiptService $service)
    {
    }
}
