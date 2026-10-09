<?php

declare(strict_types=1);

namespace App\Store\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Store\Service\SpecificationServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/specifications', name: 'manage-specifications-all-')]
#[IsGranted('ROLE_ADMIN')]
class SpecificationAllController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'product', 'price'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['status', 'sort'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'product', 'price', 'status', 'sort'];

    public function __construct(
        protected readonly SpecificationServiceInterface $service,
    ) {
    }
}
