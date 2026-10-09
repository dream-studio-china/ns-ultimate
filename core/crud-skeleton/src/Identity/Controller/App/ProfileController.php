<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Identity\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\SingleCreateAndUpdateApiViewMixin;
use App\Core\View\SingleDetailApiViewMixin;
use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Identity\Service\ProfileServiceInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/profiles', name: 'app-profiles-')]
#[IsGranted('ROLE_USER')]
class ProfileController extends RestController
{
    use ApiView, SingleDetailApiViewMixin, SingleCreateAndUpdateApiViewMixin;

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['nickname', 'avatar', 'metadata'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['nickname', 'avatar', 'metadata'];

    public function __construct(
        protected readonly ProfileServiceInterface $service,
    ) {}

    /** @return array<string, User|int> */
    protected function commonFilter(): array
    {
        $user = $this->getUser();

        return $user instanceof User ? ['user' => $user] : ['id' => -1];
    }

    /**
     * @return array<string, \App\Identity\Entity\User|null|string>
     */
    protected function defaultCreateValues(): array
    {
        $user = $this->getUser();

        return [
            'user' => $user instanceof User ? $user : null,
            'level' => Profile::LEVEL_BRONZE,
        ];
    }
}
