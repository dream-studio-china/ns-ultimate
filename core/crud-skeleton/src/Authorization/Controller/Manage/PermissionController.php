<?php

declare(strict_types=1);

namespace App\Authorization\Controller\Manage;

use App\Authorization\Service\PermissionService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/permissions', name: 'manage-permissions-')]
#[IsGranted('ROLE_ADMIN')]
class PermissionController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly PermissionService $service,
    ) {
    }
}
