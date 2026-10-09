<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\CategoryService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/categories', name: 'manage-categories-')]
#[IsGranted('ROLE_ADMIN')]
class CategoryController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'slug'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];

    public function __construct(
        protected readonly CategoryService $service
    ) {}
}
