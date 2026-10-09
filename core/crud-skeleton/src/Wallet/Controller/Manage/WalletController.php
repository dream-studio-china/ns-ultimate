<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Wallet\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Wallet\Service\WalletServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/wallets', name: 'manage-wallets-')]
#[IsGranted('ROLE_ADMIN')]
class WalletController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['user', 'currency'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['user', 'currency', 'status', 'label'];
    /**
     * Only mutable business fields. `currency` is immutable: it is the unit of
     * account of the wallet's ledger, and changing it would break transactions,
     * vouchers, and balance verification.
     *
     * @var list<string>
     */
    protected array $acceptedUpdateProperties = ['status', 'label'];

    public function __construct(
        protected readonly WalletServiceInterface $service
    ) {}

    #[Route('/balance', name: 'balance', methods: ['GET'])]
    public function verifyBalanceAction(): Response
    {
        $result = $this->service->verifyBalance();

        return $this->success($result, $result['matches'] ? 'Balance is consistent' : 'Balance MISMATCH detected');
    }

    #[Route('/reconcile', name: 'reconcile', methods: ['POST'])]
    public function reconcileAction(): Response
    {
        $result = $this->service->reconcile();

        return $this->success($result, sprintf('%d wallet(s) reconciled', $result['reconciled']));
    }
}
