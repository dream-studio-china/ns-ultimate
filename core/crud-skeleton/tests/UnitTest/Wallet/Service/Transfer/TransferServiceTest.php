<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service\Transfer;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Service\Transfer\TransferResult;
use App\Wallet\Service\Transfer\TransferService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class TransferServiceTest extends TestCase
{
    private ManagerRegistry $registry;
    private EntityManagerInterface $em;
    private Connection $connection;
    private WalletRepository $walletRepo;
    private TransactionRepository $txRepo;
    private TransferService $service;

    private bool $transactionActive = false;
    private bool $emOpen = true;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('isTransactionActive')->willReturnCallback(fn() => $this->transactionActive);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->connection);

        $this->em->method('beginTransaction')->willReturnCallback(function (): void {
            $this->transactionActive = true;
        });
        $this->em->method('commit')->willReturnCallback(function (): void {
            $this->transactionActive = false;
        });
        $this->em->method('rollback')->willReturnCallback(function (): void {
            $this->transactionActive = false;
        });
        $this->em->method('isOpen')->willReturnCallback(fn() => $this->emOpen);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManager')->willReturn($this->em);
        $this->walletRepo = $this->createMock(WalletRepository::class);
        $this->txRepo = $this->createMock(TransactionRepository::class);

        $this->service = new TransferService(
            $this->registry,
            $this->walletRepo,
            $this->txRepo,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function createWallet(int $id, int $balance, string $currency = 'CNY', string $status = 'active'): Wallet
    {
        $user = new User();
        $user->setEmail('t@t.com')->setUsername('t');
        $wallet = new Wallet($user, $currency);
        $rId = new \ReflectionProperty(Wallet::class, 'id');
        $rId->setValue($wallet, $id);
        $rBal = new \ReflectionProperty(Wallet::class, 'balance');
        $rBal->setValue($wallet, $balance);
        if ($status === 'frozen') {
            $wallet->setStatus('frozen');
        }
        return $wallet;
    }

    /** @param Wallet $wallet */
    private function setWalletBalance(Wallet $wallet, int $balance): void
    {
        $rBal = new \ReflectionProperty(Wallet::class, 'balance');
        $rBal->setValue($wallet, $balance);
    }

    /** @param Wallet $wallet */
    private function setWalletHeld(Wallet $wallet, int $held): void
    {
        $rHeld = new \ReflectionProperty(Wallet::class, 'held');
        $rHeld->setValue($wallet, $held);
    }

    private function mockQuery(): Query
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('execute')->willReturn(1);
        return $query;
    }

    // ──────────────── transfer ────────────────

    #[Group('low-value')]
    public function testTransferHappyPath(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->willReturnCallback(function (Wallet $w) use ($from, $to) {
            if ($w === $from) { $this->setWalletBalance($from, 70000); }
            if ($w === $to)   { $this->setWalletBalance($to, 30000); }
        });
        $this->em->method('persist');
        $this->em->method('flush');

        $result = $this->service->transfer(1, 2, 30000);

        self::assertSame(70000, $result->fromWalletBalanceAfter);
        self::assertSame(30000, $result->toWalletBalanceAfter);
    }

    #[Group('low-value')]
    public function testTransferAmountNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->transfer(1, 2, 0);
    }

    #[Group('low-value')]
    public function testTransferSameWallet(): void
    {
        $this->expectException(SameWalletTransferException::class);
        $this->service->transfer(1, 1, 100);
    }

    public function testTransferSourceNotFound(): void
    {
        $this->walletRepo->method('findByIdForUpdate')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source wallet #999 not found');
        $this->service->transfer(999, 1, 100);
    }

    #[Group('low-value')]
    public function testTransferTargetNotFound(): void
    {
        $from = $this->createWallet(1, 50000);
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, null],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target wallet #2 not found');
        $this->service->transfer(1, 2, 100);
    }

    #[Group('low-value')]
    public function testTransferSourceFrozen(): void
    {
        $from = $this->createWallet(1, 50000, 'CNY', 'frozen');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(WalletFrozenException::class);
        $this->service->transfer(1, 2, 100);
    }

    #[Group('low-value')]
    public function testTransferTargetFrozen(): void
    {
        $from = $this->createWallet(1, 50000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY', 'frozen');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(WalletFrozenException::class);
        $this->service->transfer(1, 2, 100);
    }

    #[Group('low-value')]
    public function testTransferCurrencyMismatch(): void
    {
        $from = $this->createWallet(1, 50000, 'CNY');
        $to = $this->createWallet(2, 0, 'USD');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Currency mismatch');
        $this->service->transfer(1, 2, 100);
    }

    #[Group('low-value')]
    public function testTransferInsufficientFunds(): void
    {
        $from = $this->createWallet(1, 100, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);

        $this->expectException(InsufficientFundsException::class);
        $this->service->transfer(1, 2, 50000);
    }

    #[Group('low-value')]
    public function testTransferIdempotent(): void
    {
        $from = $this->createWallet(1, 90000);
        $to = $this->createWallet(2, 10000);
        $existingTx = new Transaction('uuid-t', 10000, Transaction::TYPE_TRANSFER);
        $existingTx->setFromWallet($from);
        $existingTx->setToWallet($to);
        $existingTx->markCompleted();

        $this->txRepo->method('findByReferenceId')->with('ref-t')->willReturn($existingTx);

        $result = $this->service->transfer(1, 2, 99999, 'ref-t');

        self::assertSame($existingTx, $result->transaction);
        self::assertSame(90000, $result->fromWalletBalanceAfter);
        self::assertSame(10000, $result->toWalletBalanceAfter);
    }

    public function testTransferConcurrentDuplicateIsIdempotent(): void
    {
        $from = $this->createWallet(1, 90000);
        $to = $this->createWallet(2, 10000);
        $existingTx = new Transaction('uuid-t', 10000, Transaction::TYPE_TRANSFER);
        $existingTx->setFromWallet($from);
        $existingTx->setToWallet($to);
        $existingTx->markCompleted();

        $calls = 0;
        $this->txRepo->method('findByReferenceId')->willReturnCallback(
            static function () use ($existingTx, &$calls) {
                return ++$calls === 1 ? null : $existingTx;
            }
        );
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from],
            [2, $to],
        ]);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh');

        $driver = $this->createMock(DriverException::class);
        $this->em->method('flush')->willThrowException(
            new UniqueConstraintViolationException($driver, null)
        );

        $result = $this->service->transfer(1, 2, 10000, 'ref-t');

        self::assertSame($existingTx, $result->transaction);
        self::assertSame(90000, $result->fromWalletBalanceAfter);
        self::assertSame(10000, $result->toWalletBalanceAfter);
    }

    public function testTransferDeadlockSafeOrder(): void
    {
        // fromId > toId: swap lock order
        $from = $this->createWallet(2, 100000, 'CNY');
        $to = $this->createWallet(1, 0, 'CNY');
        $this->walletRepo->expects(self::exactly(2))
            ->method('findByIdForUpdate')
            ->willReturnCallback(function (int $id) use ($from, $to): ?Wallet {
                static $calls = [];
                $calls[] = $id;
                // Verify lock order: smaller ID first
                if (count($calls) === 1) {
                    self::assertSame(1, $id);
                }
                return match ($id) {
                    1 => $to,
                    2 => $from,
                    default => null,
                };
            });
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->willReturnCallback(function (Wallet $w) use ($from, $to) {
            if ($w === $from) { $this->setWalletBalance($from, 90000); }
            if ($w === $to)   { $this->setWalletBalance($to, 10000); }
        });
        $this->em->method('persist');
        $this->em->method('flush');

        $result = $this->service->transfer(2, 1, 10000);

        self::assertSame(90000, $result->fromWalletBalanceAfter);
    }

    #[Group('low-value')]
    public function testTransferRollbackOnError(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from], [2, $to],
        ]);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('DB crash'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->service->transfer(1, 2, 10000);
    }

    public function testTransferEmClosedRecovery(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from], [2, $to],
        ]);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('closed'));

        $newEm = $this->createMock(EntityManagerInterface::class);
        $newConn = $this->createMock(Connection::class);
        $newConn->method('isTransactionActive')->willReturn(false);
        $newEm->method('getConnection')->willReturn($newConn);
        $newEm->method('isOpen')->willReturn(true);

        $this->emOpen = false;

        $this->registry->expects(self::once())->method('resetManager');
        $this->registry->expects(self::once())->method('getManager')->willReturn($newEm);

        $this->expectException(\RuntimeException::class);
        $this->service->transfer(1, 2, 10000);
    }

    public function testTransferRejectsHeldFunds(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $this->setWalletHeld($from, 30000);
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from], [2, $to],
        ]);

        $this->expectException(InsufficientFundsException::class);
        // Total balance (100000) >= amount, but available (70000) < amount.
        $this->service->transfer(1, 2, 80000);
    }

    public function testTransferSucceedsWithinAvailable(): void
    {
        $from = $this->createWallet(1, 100000, 'CNY');
        $this->setWalletHeld($from, 30000);
        $to = $this->createWallet(2, 0, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->willReturnMap([
            [1, $from], [2, $to],
        ]);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->willReturnCallback(function (Wallet $w) use ($from, $to) {
            if ($w === $from) { $this->setWalletBalance($from, 30000); }
            if ($w === $to)   { $this->setWalletBalance($to, 70000); }
        });
        $this->em->method('persist');
        $this->em->method('flush');

        $result = $this->service->transfer(1, 2, 70000);

        self::assertSame(30000, $result->fromWalletBalanceAfter);
        self::assertSame(70000, $result->toWalletBalanceAfter);
    }

    // ──────────────── hold / release ────────────────

    public function testHoldHappyPath(): void
    {
        $wallet = $this->createWallet(1, 100000, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->with($wallet)->willReturnCallback(function () use ($wallet) {
            $this->setWalletHeld($wallet, 30000);
        });

        $result = $this->service->hold(1, 30000, 'Escrow');

        self::assertSame($wallet, $result);
        self::assertSame(30000, $result->getHeld());
        self::assertSame(100000, $result->getBalance());
        self::assertSame(70000, $result->getAvailableBalance());
    }

    public function testHoldAmountNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hold amount must be positive');
        $this->service->hold(1, 0);
    }

    public function testHoldWalletNotFound(): void
    {
        $this->walletRepo->method('findByIdForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet #999 not found');
        $this->service->hold(999, 10000);
    }

    public function testHoldWalletFrozen(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY', 'frozen');
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(WalletFrozenException::class);
        $this->service->hold(1, 10000);
    }

    public function testHoldInsufficientAvailable(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(InsufficientFundsException::class);
        $this->service->hold(1, 80000);
    }

    public function testHoldRollbackOnError(): void
    {
        $wallet = $this->createWallet(1, 100000, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('DB failure'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->service->hold(1, 10000);
    }

    public function testReleaseHappyPath(): void
    {
        $wallet = $this->createWallet(1, 100000, 'CNY');
        $this->setWalletHeld($wallet, 30000);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->with($wallet)->willReturnCallback(function () use ($wallet) {
            $this->setWalletHeld($wallet, 0);
        });

        $result = $this->service->release(1, 30000, 'Escrow released');

        self::assertSame($wallet, $result);
        self::assertSame(0, $result->getHeld());
        self::assertSame(100000, $result->getAvailableBalance());
    }

    public function testReleaseAmountNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Release amount must be positive');
        $this->service->release(1, 0);
    }

    public function testReleaseMoreThanHeld(): void
    {
        $wallet = $this->createWallet(1, 100000, 'CNY');
        $this->setWalletHeld($wallet, 10000);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(InsufficientFundsException::class);
        $this->service->release(1, 20000);
    }

    public function testReleaseWalletNotFound(): void
    {
        $this->walletRepo->method('findByIdForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet #999 not found');
        $this->service->release(999, 10000);
    }
}
