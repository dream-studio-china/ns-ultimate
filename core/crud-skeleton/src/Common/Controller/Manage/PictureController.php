<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\PictureService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/pictures', name: 'manage-pictures-')]
#[IsGranted('ROLE_ADMIN')]
class PictureController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['category', 'image'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['user', 'title', 'category', 'image', 'metadata'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['user', 'title', 'category', 'image', 'metadata'];

    public function __construct(
        protected readonly PictureService $service
    ) {}
}
