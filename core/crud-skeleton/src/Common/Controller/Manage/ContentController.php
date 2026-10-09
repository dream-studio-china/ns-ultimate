<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\ContentService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/contents', name: 'manage-contents-')]
#[IsGranted('ROLE_ADMIN')]
class ContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['title'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['title', 'body', 'category', 'tags', 'metadata'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['title', 'body', 'category', 'tags', 'metadata'];

    public function __construct(
        protected readonly ContentService $service
    ) {}
}
