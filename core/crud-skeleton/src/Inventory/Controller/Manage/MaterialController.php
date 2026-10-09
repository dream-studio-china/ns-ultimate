<?php

declare(strict_types=1);

namespace App\Inventory\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Inventory\Service\MaterialServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/inventory/materials', name: 'manage-inventory-materials-')]
#[IsGranted('ROLE_ADMIN')]
final class MaterialController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['code', 'name', 'kind', 'unit'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['code', 'name', 'kind', 'unit', 'metadata', 'status'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'kind', 'unit', 'metadata', 'status'];

    public function __construct(
        protected readonly MaterialServiceInterface $service,
    ) {
    }
}
