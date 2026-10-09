<?php

declare(strict_types=1);

namespace App\Trade\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Trade\Service\OrderItemServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/order-items', name: 'manage-order-items-')]
#[IsGranted('ROLE_ADMIN')]
class OrderItemController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'price'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'price', 'status', 'sort'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'price', 'status', 'sort'];

    public function __construct(
        protected readonly OrderItemServiceInterface $service,
    ) {
    }
}
