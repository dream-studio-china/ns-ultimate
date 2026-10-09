<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\CommentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/comments', name: 'app-comments-')]
class CommentController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['body', 'entityType', 'entityId'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['parent'];

    public function __construct(
        protected readonly CommentServiceInterface $service
    ) {}

    /** @return array<string, mixed> */
    protected function commonFilter()
    {
        return [
            'author' => $this->getUser(), 
        ];
    }

    /** @return array<string, mixed> */
    protected function defaultCreateValues(): array
    {
        $user = $this->getUser();

        if ($user instanceof User) {
            return [
                'status' => 'pending',
                'author' => $user->getId(),
                'authorName' => $user->getUsername(),
                'authorEmail' => $user->getEmail(),
            ];
        }

        return ['status' => 'pending'];
    }
}
