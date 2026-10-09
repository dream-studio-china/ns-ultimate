<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Service\WalletServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/wallets', name: 'app-wallets-')]
#[IsGranted('ROLE_USER')]
class WalletController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly WalletServiceInterface $service,
    ) {}

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $user = $this->getUser();

        return $user instanceof User ? ['user' => $user] : ['id' => -1];
    }

    #[Route('/balance', name: 'balance', methods: ['GET'])]
    public function verifyBalanceAction(): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $result = $this->service->verifyBalanceForUser($user);

        return $this->success($result, $result['matches'] ? 'Balance is consistent' : 'Balance MISMATCH detected');
    }
}
