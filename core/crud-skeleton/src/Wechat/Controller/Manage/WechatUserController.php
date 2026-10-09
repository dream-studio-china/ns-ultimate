<?php

declare(strict_types=1);

namespace App\Wechat\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Wechat\Service\WechatUserServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wechat-users', name: 'manage-wechat-users-')]
#[IsGranted('ROLE_ADMIN')]
class WechatUserController extends RestController
{
    use ApiView,
        DetailApiViewMixin,
        ListApiViewMixin,
        CreateApiViewMixin,
        UpdateApiViewMixin,
        DeleteApiViewMixin;

    public function __construct(
        protected readonly WechatUserServiceInterface $service
    ) {}
}
