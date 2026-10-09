<?php

declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use App\Core\Utils\UUID;
use App\Wallet\Entity\Transaction;
use App\Wallet\Entity\Voucher;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Service\Concern\WrapInTransactionTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * The single gate for funds entering the wallet system. Every deposit must be
 * backed by a registered voucher type; a credit is performed atomically with
 * the voucher record (single-sided: toWallet only, no fromWallet). Reversal is
 * its mirror: a single-sided debit (fromWallet only) that returns the funds to
 * the source, so the boundary invariant SUM(balance) == SUM(credit vouchers) -
 * SUM(debit vouchers) stays exact.
 */
final class DepositService implements DepositServiceInterface
{
    use WrapInTransactionTrait;

    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly VoucherRepository $voucherRepository,
        private readonly DepositProviderRegistry $depositRegistry,
        private readonly WalletRepository $walletRepository,
        private readonly LoggerInterface $logger,
    ) {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();
        $this->em = $em;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function deposit(
        string $voucherType,
        string $voucherId,
        int $walletId,
        int $amount,
        string $currency,
        string $referenceId,
        string $createdBy,
        ?string $reason = null,
        string $fundSource = Voucher::FUND_SOURCE_EXTERNAL,
        array $options = []
    ): Voucher {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be positive');
        }
        if ($referenceId === '') {
            throw new \InvalidArgumentException('Reference id is required');
        }

        $existing = $this->voucherRepository->findByReferenceId($referenceId);
        if ($existing instanceof Voucher) {
            return $existing;
        }

        $existing = $this->voucherRepository->findByVoucherSource($voucherType, $voucherId);
        if ($existing instanceof Voucher) {
            throw new \RuntimeException(sprintf(
                'Voucher source %s/%s already processed (status %s).',
                $voucherType,
                $voucherId,
                $existing->getStatus(),
            ));
        }

        $provider = $this->depositRegistry->forVoucherType($voucherType);
        if ($provider === null) {
            throw new \InvalidArgumentException(sprintf('Unsupported voucher type "%s".', $voucherType));
        }

        $provider->assertPermitted($options);

        try {
            return $this->wrapInTransaction(function () use (
                $voucherType, $voucherId, $walletId, $amount, $currency,
                $referenceId, $createdBy, $reason, $fundSource, $options, $provider,
            ) {
            $wallet = $this->walletRepository->findByIdForUpdate($walletId);
            if ($wallet === null) {
                throw new \RuntimeException("Target wallet #$walletId not found");
            }
            if ($wallet->isFrozen()) {
                throw new WalletFrozenException($walletId);
            }
            if ($wallet->getCurrency() !== strtoupper($currency)) {
                throw new \InvalidArgumentException(sprintf(
                    'Currency mismatch: %s vs %s',
                    $wallet->getCurrency(),
                    $currency,
                ));
            }

            $voucher = new Voucher(
                $wallet,
                Voucher::DIRECTION_CREDIT,
                $fundSource,
                $voucherType,
                $voucherId,
                $amount,
                $wallet->getCurrency(),
                $referenceId,
                $createdBy,
                $reason,
            );

            $provider->authorize($voucher, $options);

            $this->em->createQuery(
                'UPDATE App\Wallet\Entity\Wallet w SET w.balance = w.balance + :amount, w.version = w.version + 1 WHERE w.id = :id'
            )
                ->setParameter('amount', $amount)
                ->setParameter('id', $walletId)
                ->execute();
            $this->em->refresh($wallet);

            $tx = new Transaction(UUID::v4(), $amount, Transaction::TYPE_DEPOSIT);
            $tx->setToWallet($wallet);
            $tx->setReferenceId('deposit-' . $voucher->getUuid());
            $tx->setDescription($reason);
            $tx->markCompleted();

            $voucher->markApplied($tx->getUuid());

            $this->em->persist($voucher);
            $this->em->persist($tx);

            $this->logger->info('Deposit applied', [
                'uuid' => $voucher->getUuid(),
                'walletId' => $walletId,
                'amount' => $amount,
                'voucherType' => $voucherType,
                'createdBy' => $createdBy,
            ]);

            return $voucher;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->voucherRepository->findByReferenceId($referenceId);
            if ($existing instanceof Voucher) {
                return $existing;
            }
            $existing = $this->voucherRepository->findByVoucherSource($voucherType, $voucherId);
            if ($existing instanceof Voucher) {
                throw new \RuntimeException(sprintf(
                    'Voucher source %s/%s already processed (status %s).',
                    $voucherType,
                    $voucherId,
                    $existing->getStatus(),
                ));
            }

            throw $e;
        }
    }

    /**
     * Reverse an applied credit (deposit) voucher with a single-sided debit:
     * the credited funds leave the wallet and return to the source. Requires
     * the funds to still be available in the credited wallet.
     *
     * @param array<string, mixed> $options
     */
    public function reverse(string $voucherUuid, string $reason, array $options = []): Voucher
    {
        $voucher = $this->voucherRepository->findByUuid($voucherUuid);
        if ($voucher === null) {
            throw new \RuntimeException(sprintf('Voucher "%s" not found.', $voucherUuid));
        }
        if ($voucher->getStatus() !== Voucher::STATUS_APPLIED) {
            throw new \LogicException(sprintf('Voucher cannot be reversed from status "%s".', $voucher->getStatus()));
        }
        if ($voucher->getDirection() !== Voucher::DIRECTION_CREDIT) {
            throw new \InvalidArgumentException('Only credit (deposit) vouchers can be reversed.');
        }

        $amount = $voucher->getAmount();
        $walletId = $voucher->getWallet()->getId();
        \assert($walletId !== null);

        $this->wrapInTransaction(function () use ($voucher, $walletId, $amount, $reason) {
            $wallet = $this->walletRepository->findByIdForUpdate($walletId);
            if ($wallet === null) {
                throw new \RuntimeException("Wallet #$walletId not found");
            }
            if ($wallet->getAvailableBalance() < $amount) {
                throw new InsufficientFundsException($walletId, $wallet->getAvailableBalance(), $amount);
            }

            $this->em->createQuery(
                'UPDATE App\Wallet\Entity\Wallet w SET w.balance = w.balance - :amount, w.version = w.version + 1 WHERE w.id = :id'
            )
                ->setParameter('amount', $amount)
                ->setParameter('id', $walletId)
                ->execute();
            $this->em->refresh($wallet);

            $tx = new Transaction(UUID::v4(), $amount, Transaction::TYPE_CREDIT_REVERSAL);
            $tx->setFromWallet($wallet);
            $tx->setReferenceId('deposit-reverse-' . $voucher->getUuid());
            $tx->setDescription($reason);
            $tx->markCompleted();

            $voucher->markReversed($tx->getUuid(), $reason);

            $this->em->persist($tx);

            $this->logger->info('Deposit reversed', [
                'uuid' => $voucher->getUuid(),
                'walletId' => $walletId,
                'amount' => $amount,
            ]);
        });

        $provider = $this->depositRegistry->forVoucherType($voucher->getVoucherType());
        if ($provider !== null) {
            $provider->reverse($voucher, $reason, $options);
        }

        return $voucher;
    }
}
