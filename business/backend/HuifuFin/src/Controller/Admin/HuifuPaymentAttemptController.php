<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use NsUltimate\Business\HuifuFin\Service\HuifuPaymentAttemptService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/huifu-payment-attempts', name: 'manage-huifu-payment-attempts-')]
#[IsGranted('ROLE_ADMIN')]
final class HuifuPaymentAttemptController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(protected readonly HuifuPaymentAttemptService $service)
    {
    }
}
