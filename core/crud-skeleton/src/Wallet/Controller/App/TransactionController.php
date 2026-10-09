<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Wallet\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Service\TransactionServiceInterface;
use App\Wallet\Service\Transfer\TransferResult;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Transaction resource for the current user: create a transfer out of their own
 * wallet through the standard create lifecycle, plus list/detail of their
 * ledger. Update/Delete mixins are intentionally omitted: the ledger is
 * append-only.
 */
#[Route('/app/transactions', name: 'app-transactions-')]
#[IsGranted('ROLE_USER')]
class TransactionController extends RestController
{
    use ApiView, CreateApiViewMixin, DetailApiViewMixin, ListApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['fromWalletId', 'toWalletId', 'amount'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['fromWalletId', 'toWalletId', 'amount', 'referenceId', 'description'];

    private ?TransferResult $lastTransfer = null;

    public function __construct(
        protected readonly TransactionServiceInterface $service,
        private readonly TransactionRepository $transactionRepository,
        private readonly TransferServiceInterface $transferService,
        private readonly WalletRepository $walletRepository,
    ) {}

    protected function commonFilter(): QueryBuilder
    {
        $user = $this->getUser();

        return $this->transactionRepository->createQueryBuilder('entity')
            ->leftJoin('entity.fromWallet', 'fromWallet')
            ->leftJoin('entity.toWallet', 'toWallet')
            ->andWhere('fromWallet.user = :user OR toWallet.user = :user')
            ->setParameter('user', $user)
            ->addOrderBy('entity.createdAt', 'DESC');
    }

    /**
     * @param array<string, mixed> $content
     */
    protected function processEntity(array $content, object $entity): object
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $fromWalletId = (int) $content['fromWalletId'];
        $fromWallet = $this->walletRepository->find($fromWalletId);
        if (!$fromWallet instanceof Wallet || $fromWallet->getUser()?->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Source wallet not found');
        }

        try {
            $this->lastTransfer = $this->transferService->transfer(
                $fromWalletId,
                (int) $content['toWalletId'],
                (int) $content['amount'],
                isset($content['referenceId']) ? (string) $content['referenceId'] : null,
                isset($content['description']) ? (string) $content['description'] : null,
            );
        } catch (InsufficientFundsException|WalletFrozenException|SameWalletTransferException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                throw new NotFoundHttpException($e->getMessage());
            }
            throw $e;
        }

        return $this->lastTransfer->transaction;
    }

    /**
     * @return array<string, mixed>
     */
    protected function afterCreated(object|false $entity): mixed
    {
        if (!$entity instanceof Transaction || !$this->lastTransfer instanceof TransferResult) {
            return [];
        }

        return [
            'transactionId' => $entity->getId(),
            'uuid' => $entity->getUuid(),
            'fromWalletId' => $entity->getFromWallet()?->getId(),
            'toWalletId' => $entity->getToWallet()?->getId(),
            'amount' => $entity->getAmount(),
            'amountFloat' => $entity->getAmountAsFloat(),
            'status' => $entity->getStatus(),
            'fromWalletBalanceAfter' => $this->lastTransfer->fromWalletBalanceAfter,
            'toWalletBalanceAfter' => $this->lastTransfer->toWalletBalanceAfter,
            'createdAt' => $entity->getCreatedAt(),
        ];
    }
}
