<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\Manage;

use App\Common\Service\CommentService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/comments', name: 'manage-comments-')]
#[IsGranted('ROLE_ADMIN')]
class CommentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['body', 'entityType', 'entityId'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['body', 'entityType', 'entityId', 'authorName', 'authorEmail', 'author', 'parent', 'status'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['body', 'authorName', 'authorEmail', 'status'];

    public function __construct(
        protected readonly CommentService $service
    ) {}
}
