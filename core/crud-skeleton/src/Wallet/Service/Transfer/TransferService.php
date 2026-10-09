<?php

declare(strict_types=1);

namespace App\Wallet\Service\Transfer;

use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Service\Concern\WrapInTransactionTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final class TransferService implements TransferServiceInterface
{
    use WrapInTransactionTrait;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly WalletRepository $walletRepo,
        private readonly TransactionRepository $transactionRepo,
        private readonly LoggerInterface $logger,
    ) {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();
        $this->em = $em;
    }

    public function transfer(
        int $fromWalletId,
        int $toWalletId,
        int $amount,
        ?string $referenceId = null,
        ?string $description = null
    ): TransferResult {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be positive');
        }

        if ($fromWalletId === $toWalletId) {
            throw new SameWalletTransferException();
        }

        // Idempotency check
        if ($referenceId !== null) {
            $existing = $this->transactionRepo->findByReferenceId($referenceId);
            if ($existing !== null) {
                $this->logger->info('Transfer already processed (idempotent)', ['referenceId' => $referenceId]);
                return new TransferResult(
                    $existing,
                    $existing->getFromWallet()?->getBalance() ?? 0,
                    $existing->getToWallet()?->getBalance() ?? 0,
                );
            }
        }

        $uuid = self::generateUuid();

        try {
            return $this->wrapInTransaction(function () use ($fromWalletId, $toWalletId, $amount, $referenceId, $description, $uuid) {
            // Lock wallets in consistent order (by ID) to prevent deadlocks
            [$firstId, $secondId] = $fromWalletId < $toWalletId
                ? [$fromWalletId, $toWalletId]
                : [$toWalletId, $fromWalletId];

            $first = $this->walletRepo->findByIdForUpdate($firstId);
            $second = $this->walletRepo->findByIdForUpdate($secondId);

            $fromWallet = $fromWalletId < $toWalletId ? $first : $second;
            $toWallet = $fromWalletId < $toWalletId ? $second : $first;

            if ($fromWallet === null) {
                throw new \RuntimeException("Source wallet #$fromWalletId not found");
            }
            if ($toWallet === null) {
                throw new \RuntimeException("Target wallet #$toWalletId not found");
            }

            if ($fromWallet->isFrozen()) {
                throw new WalletFrozenException($fromWalletId);
            }
            if ($toWallet->isFrozen()) {
                throw new WalletFrozenException($toWalletId);
            }

            if ($fromWallet->getCurrency() !== $toWallet->getCurrency()) {
                throw new \RuntimeException(sprintf(
                    'Currency mismatch: %s vs %s',
                    $fromWallet->getCurrency(), $toWallet->getCurrency()
                ));
            }

            if ($fromWallet->getAvailableBalance() < $amount) {
                throw new InsufficientFundsException($fromWalletId, $fromWallet->getAvailableBalance(), $amount);
            }

            // Atomic balance updates
            $this->em->createQuery(
                'UPDATE App\Wallet\Entity\Wallet w SET w.balance = w.balance - :amount, w.version = w.version + 1 WHERE w.id = :id'
            )
                ->setParameter('amount', $amount)
                ->setParameter('id', $fromWalletId)
                ->execute();

            $this->em->createQuery(
                'UPDATE App\Wallet\Entity\Wallet w SET w.balance = w.balance + :amount, w.version = w.version + 1 WHERE w.id = :id'
            )
                ->setParameter('amount', $amount)
                ->setParameter('id', $toWalletId)
                ->execute();

            $this->em->refresh($fromWallet);
            $this->em->refresh($toWallet);

            $transaction = new Transaction($uuid, $amount, Transaction::TYPE_TRANSFER);
            $transaction->setFromWallet($fromWallet);
            $transaction->setToWallet($toWallet);
            $transaction->setReferenceId($referenceId);
            $transaction->setDescription($description);
            $transaction->markCompleted();

            $this->em->persist($transaction);

            $this->logger->info('Transfer completed', [
                'uuid' => $uuid,
                'from' => $fromWalletId,
                'to' => $toWalletId,
                'amount' => $amount,
            ]);

            return new TransferResult($transaction, $fromWallet->getBalance(), $toWallet->getBalance());
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($referenceId === null) {
                throw $e;
            }
            $existing = $this->transactionRepo->findByReferenceId($referenceId);
            if ($existing !== null) {
                return new TransferResult(
                    $existing,
                    $existing->getFromWallet()?->getBalance() ?? 0,
                    $existing->getToWallet()?->getBalance() ?? 0,
                );
            }

            throw $e;
        }
    }

    public function hold(int $walletId, int $amount, ?string $description = null): Wallet
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Hold amount must be positive');
        }

        return $this->mutateHeld($walletId, $amount, $description);
    }

    public function release(int $walletId, int $amount, ?string $description = null): Wallet
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Release amount must be positive');
        }

        return $this->mutateHeld($walletId, -$amount, $description);
    }

    /**
     * Moves `$amount` (positive = hold, negative = release) between the wallet's
     * available and held buckets without changing the total balance. Holds are
     * not written to the ledger; the WalletWithdrawal/hold entity owns audit.
     */
    private function mutateHeld(int $walletId, int $amount, ?string $description = null): Wallet
    {
        return $this->wrapInTransaction(function () use ($walletId, $amount, $description) {
            $wallet = $this->walletRepo->findByIdForUpdate($walletId);

            if ($wallet === null) {
                throw new \RuntimeException("Wallet #$walletId not found");
            }
            if ($wallet->isFrozen()) {
                throw new WalletFrozenException($walletId);
            }

            if ($amount > 0) {
                if ($wallet->getAvailableBalance() < $amount) {
                    throw new InsufficientFundsException($walletId, $wallet->getAvailableBalance(), $amount);
                }
            } elseif ($wallet->getHeld() < -$amount) {
                throw new InsufficientFundsException($walletId, $wallet->getHeld(), -$amount);
            }

            $this->em->createQuery(
                'UPDATE App\Wallet\Entity\Wallet w SET w.held = w.held + :amount, w.version = w.version + 1 WHERE w.id = :id'
            )
                ->setParameter('amount', $amount)
                ->setParameter('id', $walletId)
                ->execute();

            $this->em->refresh($wallet);

            $this->logger->info(
                $amount > 0 ? 'Hold applied' : 'Hold released',
                ['walletId' => $walletId, 'amount' => $amount, 'description' => $description],
            );

            return $wallet;
        });
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
