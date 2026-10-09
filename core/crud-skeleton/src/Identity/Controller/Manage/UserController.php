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
use App\Identity\Entity\User;
use App\Identity\Service\UserServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/users', name: 'manage-users-')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['email', 'username', 'password'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['email', 'username', 'password', 'phone', 'phoneVerified', 'roles'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['email', 'username', 'password', 'phone', 'phoneVerified', 'roles'];

    public function __construct(
        protected readonly UserServiceInterface $service,
    ) {}

    #[Route('/{id<\d+>}/change-password', name: 'change-password', methods: ['POST'])]
    public function changePasswordAction(Request $request, int $id): Response
    {
        $user = $this->service->get(['id' => $id]);
        if (!$user instanceof User) {
            return $this->warning('User not found.', 404, '', 404);
        }

        $content = json_decode($request->getContent(), true) ?: [];

        try {
            $this->service->adminChangePassword($user, (string) ($content['newPassword'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success(null, 'Password changed');
    }
}
