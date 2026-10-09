<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Identity\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Identity\Entity\User;
use App\Identity\Service\UserServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/users', name: 'app-users-')]
class UserController extends RestController
{
    use ApiView;

    public function __construct(
        protected readonly UserServiceInterface $service,
    ) {}

    #[Route('/change-password', name: 'change-password', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePasswordAction(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->warning('Not authenticated.', 401, '', 401);
        }

        $content = json_decode($request->getContent(), true) ?: [];

        try {
            $this->service->changePassword(
                $user,
                (string) ($content['currentPassword'] ?? ''),
                (string) ($content['newPassword'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success(null, 'Password changed');
    }

    #[Route('/me', name: 'profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function profileAction(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->warning('Not authenticated.', 401, '', 401);
        }

        return $this->success($user);
    }

    #[Route('/me', name: 'profile-update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfileAction(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->warning('Not authenticated.', 401, '', 401);
        }

        try {
            $user = $this->service->updateProfile($user, json_decode($request->getContent(), true) ?: []);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($user, 'Profile updated');
    }
}
