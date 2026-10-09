<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Core\Service\BaseService;
use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Repository\VoucherRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Wallet\Entity\Wallet> */
final class WalletService extends BaseService implements WalletServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly TransactionRepository $transactionRepo,
        private readonly VoucherRepository $voucherRepo,
    ) {
        parent::__construct($container, Wallet::class);
    }

    /**
     * Verify the boundary invariant per unit of account:
     * SUM(balance) == SUM(applied credit vouchers) - SUM(applied debit vouchers).
     * Legacy deposits (TYPE_DEPOSIT transactions without a voucher) are reported
     * separately and treated as part of the expected balance.
     *
     * @return array<string, mixed>
     */
    public function verifyBalance(): array
    {
        return $this->verifyByUnit(
            $this->getWalletRepository()->getTotalBalanceByUnit(),
            $this->voucherRepo->getBoundaryTotalByUnit(),
            $this->transactionRepo->getUnbackedDepositsByUnit(),
            $this->getWalletRepository()->count([]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyBalanceForUser(User $user): array
    {
        $userId = (int) $user->getId();

        return $this->verifyByUnit(
            $this->getWalletRepository()->getTotalBalanceByUnit($userId),
            $this->voucherRepo->getBoundaryTotalByUnit($userId),
            $this->transactionRepo->getUnbackedDepositsByUnit($userId),
            $this->getWalletRepository()->count(['user' => $user]),
        );
    }

    /**
     * @param list<array{currency: string, total: int}> $balanceByUnit
     * @param array<string, int> $boundaryByUnit
     * @param array<string, int> $legacyByUnit
     * @return array<string, mixed>
     */
    private function verifyByUnit(array $balanceByUnit, array $boundaryByUnit, array $legacyByUnit, int $walletCount): array
    {
        $balance = [];
        foreach ($balanceByUnit as $row) {
            $balance[$row['currency']] = $row['total'];
        }

        $currencies = array_unique(array_merge(
            array_keys($balance),
            array_keys($boundaryByUnit),
            array_keys($legacyByUnit),
        ));
        sort($currencies);

        $units = [];
        $allMatches = true;
        foreach ($currencies as $currency) {
            $total = $balance[$currency] ?? 0;
            $boundary = $boundaryByUnit[$currency] ?? 0;
            $legacy = $legacyByUnit[$currency] ?? 0;
            $expected = $boundary + $legacy;
            $matches = $total === $expected;
            $allMatches = $allMatches && $matches;
            $units[] = [
                'currency' => $currency,
                'totalBalance' => $total,
                'voucherBoundary' => $boundary,
                'unmatchedDeposits' => $legacy,
                'expected' => $expected,
                'matches' => $matches,
            ];
        }

        return [
            'units' => $units,
            'matches' => $allMatches,
            'walletCount' => $walletCount,
        ];
    }

    /**
     * Reconcile every wallet: compare actual balance against transaction-derived
     * expected balance. For wallets that have more balance than their transaction
     * history supports (legacy data from old direct-set balance), create an
     * adjustment deposit transaction to acknowledge the balance — without
     * touching the wallet balance itself.
     *
     * actual < expected (real gap): reported, not auto-corrected.
     *
     * Idempotent: re-running produces no new adjustments when books are balanced.
     *
     * @return array{reconciled: int, adjustments: list<array<string,mixed>>}
     */
    public function reconcile(): array
    {
        $wallets = $this->getWalletRepository()->findAll();
        $adjustments = [];
        $reconciled = 0;

        $uuidFn = static function (): string {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        };

        foreach ($wallets as $wallet) {
            if (!($wallet instanceof Wallet) || $wallet->getId() === null) {
                continue;
            }

            $walletId = $wallet->getId();
            $actual = $wallet->getBalance();
            $expected = $this->transactionRepo->getExpectedBalance($walletId);
            $diff = $actual - $expected;

            if ($diff === 0) {
                continue;
            }

            if ($diff < 0) {
                $adjustments[] = [
                    'walletId' => $walletId,
                    'actual' => $actual,
                    'expected' => $expected,
                    'diff' => $diff,
                    'action' => 'skipped_negative',
                    'note' => 'Balance less than expected — manual review required',
                ];
                continue;
            }

            // actual > expected: legacy balance, create deposit to bridge the gap.
            // The wallet balance stays as-is; the deposit transaction acknowledges it.
            $tx = new Transaction($uuidFn(), $diff, Transaction::TYPE_ADJUSTMENT);
            $tx->setToWallet($wallet);
            $tx->setDescription(sprintf(
                'Reconciliation — actual %d, expected %d, gap +%d acknowledged as deposit',
                $actual,
                $expected,
                $diff,
            ));
            $tx->markCompleted();

            $this->em->persist($tx);
            $this->em->flush();

            $adjustments[] = [
                'walletId' => $walletId,
                'actual' => $actual,
                'expected' => $expected,
                'diff' => $diff,
                'action' => 'deposited',
                'newBalance' => $wallet->getBalance(),
            ];
            $reconciled++;
        }

        return [
            'reconciled' => $reconciled,
            'adjustments' => $adjustments,
        ];
    }

    private function getWalletRepository(): \App\Wallet\Repository\WalletRepository
    {
        $repository = $this->getRepository(Wallet::class);
        if (!$repository instanceof \App\Wallet\Repository\WalletRepository) {
            throw new \LogicException('Wallet repository is not available.');
        }

        return $repository;
    }
}
