<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Identity\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Identity\Service\ProfileServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/profiles', name: 'manage-profiles-')]
#[IsGranted('ROLE_ADMIN')]
class ProfileController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['user', 'level'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['user', 'level', 'nickname', 'avatar', 'metadata', 'joinedAt'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['level', 'nickname', 'avatar', 'metadata', 'joinedAt'];

    public function __construct(
        protected readonly ProfileServiceInterface $service,
    ) {}
}
