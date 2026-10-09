<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service\Withdraw;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Entity\Voucher;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Withdraw\WithdrawProviderInterface;
use App\Wallet\Service\Withdraw\WithdrawProviderRegistry;
use App\Wallet\Service\Withdraw\WithdrawService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class RecordingWithdrawProvider implements WithdrawProviderInterface
{
    public bool $authorized = false;
    public bool $reversed = false;
    public ?\Throwable $permitException = null;

    public function __construct(private readonly string $supportedType)
    {
    }

    public static function getName(): string
    {
        return 'test';
    }

    public function supports(string $voucherType): bool
    {
        return $voucherType === $this->supportedType;
    }

    public function assertPermitted(array $options = []): void
    {
        if ($this->permitException !== null) {
            throw $this->permitException;
        }
    }

    public function authorize(Voucher $voucher, array $options): void
    {
        $this->authorized = true;
    }

    public function reverse(Voucher $voucher, string $reason, array $options = []): void
    {
        $this->reversed = true;
    }
}

#[AllowMockObjectsWithoutExpectations]
final class WithdrawServiceTest extends TestCase
{
    private ManagerRegistry $registry;
    private EntityManagerInterface $em;
    private Connection $connection;
    private VoucherRepository $voucherRepo;
    private WithdrawProviderRegistry $providerRegistry;
    private WalletRepository $walletRepo;
    private RecordingWithdrawProvider $provider;
    private WithdrawService $service;

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

        $this->voucherRepo = $this->createMock(VoucherRepository::class);
        $this->walletRepo = $this->createMock(WalletRepository::class);
        $this->provider = new RecordingWithdrawProvider(Voucher::VOUCHER_TYPE_MANUAL);
        $this->providerRegistry = new WithdrawProviderRegistry([$this->provider]);

        $this->service = new WithdrawService(
            $this->registry,
            $this->voucherRepo,
            $this->providerRegistry,
            $this->walletRepo,
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

    private function setWalletBalance(Wallet $wallet, int $balance): void
    {
        $rBal = new \ReflectionProperty(Wallet::class, 'balance');
        $rBal->setValue($wallet, $balance);
    }

    private function createAppliedDebitVoucher(Wallet $wallet, int $amount, string $uuid = 'voucher-uuid'): Voucher
    {
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_DEBIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-' . $uuid,
            $amount,
            $wallet->getCurrency(),
            'ref-' . $uuid,
            'admin',
        );
        $voucher->markApplied('debit-tx-' . $uuid);
        return $voucher;
    }

    private function mockQuery(): Query
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('execute')->willReturn(1);
        return $query;
    }

    // ──────────────── withdraw ────────────────

    public function testWithdrawHappyPath(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->with($wallet)->willReturnCallback(function () use ($wallet) {
            $this->setWalletBalance($wallet, 20000);
        });
        $captured = null;
        $this->em->method('persist')->willReturnCallback(function (mixed $entity) use (&$captured): void {
            if ($entity instanceof Transaction) {
                $captured = $entity;
            }
        });
        $this->em->method('flush');

        $voucher = $this->service->withdraw(
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            1,
            30000,
            'CNY',
            'ref-wd-1',
            'admin',
            'Manual payout',
        );

        self::assertTrue($this->provider->authorized);
        self::assertSame(Voucher::STATUS_APPLIED, $voucher->getStatus());
        self::assertSame(Voucher::DIRECTION_DEBIT, $voucher->getDirection());
        self::assertSame(Voucher::FUND_SOURCE_EXTERNAL, $voucher->getFundSource());
        self::assertSame($wallet, $voucher->getWallet());
        self::assertSame(30000, $voucher->getAmount());
        self::assertSame('admin', $voucher->getCreatedBy());
        self::assertNotNull($voucher->getTransactionId());
        self::assertSame(20000, $wallet->getBalance());

        self::assertInstanceOf(Transaction::class, $captured);
        self::assertSame(Transaction::TYPE_WITHDRAWAL, $captured->getType());
        self::assertSame(30000, $captured->getAmount());
        self::assertSame($wallet, $captured->getFromWallet());
        self::assertNull($captured->getToWallet());
        self::assertStringStartsWith('withdraw-', $captured->getReferenceId());
        self::assertTrue($captured->isCompleted());
    }

    public function testWithdrawAmountNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Withdrawal amount must be positive');
        $this->service->withdraw('manual', 'm1', 1, 0, 'CNY', 'ref', 'admin');
    }

    public function testWithdrawRequiresReferenceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference id is required');
        $this->service->withdraw('manual', 'm1', 1, 100, 'CNY', '', 'admin');
    }

    public function testWithdrawIdempotent(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $existing = $this->createAppliedDebitVoucher($wallet, 30000, 'same');
        $this->voucherRepo->method('findByReferenceId')->with('ref-wd-1')->willReturn($existing);

        $result = $this->service->withdraw('manual', 'other', 1, 99999, 'CNY', 'ref-wd-1', 'admin');

        self::assertSame($existing, $result);
    }

    public function testWithdrawRejectsDuplicateVoucherSource(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $existing = $this->createAppliedDebitVoucher($wallet, 30000, 'dup');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->with('manual', 'manual-1')->willReturn($existing);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already processed');
        $this->service->withdraw('manual', 'manual-1', 1, 100, 'CNY', 'ref-new', 'admin');
    }

    public function testWithdrawRejectsUnsupportedVoucherType(): void
    {
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported voucher type "transfer".');
        $this->service->withdraw('transfer', 'x', 1, 100, 'CNY', 'ref-t', 'admin');
    }

    public function testWithdrawDeniedByProviderPermission(): void
    {
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->provider->permitException = new AccessDeniedException('Manual voucher type is admin-only.');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Manual voucher type is admin-only.');
        $this->service->withdraw(Voucher::VOUCHER_TYPE_MANUAL, 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    public function testWithdrawConcurrentDuplicateIsIdempotent(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $existing = $this->createAppliedDebitVoucher($wallet, 30000, 'race');

        $calls = 0;
        $this->voucherRepo->method('findByReferenceId')->willReturnCallback(
            static function () use ($existing, &$calls) {
                return ++$calls === 1 ? null : $existing;
            }
        );
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh');
        $this->em->method('persist');

        $driver = $this->createMock(DriverException::class);
        $this->em->method('flush')->willThrowException(
            new UniqueConstraintViolationException($driver, null)
        );

        $result = $this->service->withdraw(
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-race',
            1,
            30000,
            'CNY',
            'ref-race',
            'admin',
        );

        self::assertSame($existing, $result);
    }

    public function testWithdrawWalletNotFound(): void
    {
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source wallet #999 not found');
        $this->service->withdraw('manual', 'm1', 999, 100, 'CNY', 'ref', 'admin');
    }

    public function testWithdrawWalletFrozen(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY', 'frozen');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(WalletFrozenException::class);
        $this->service->withdraw('manual', 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    public function testWithdrawCurrencyMismatch(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency mismatch');
        $this->service->withdraw('manual', 'm1', 1, 100, 'USD', 'ref', 'admin');
    }

    public function testWithdrawInsufficientAvailable(): void
    {
        $wallet = $this->createWallet(1, 10000, 'CNY');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(InsufficientFundsException::class);
        $this->service->withdraw('manual', 'm1', 1, 30000, 'CNY', 'ref', 'admin');
    }

    public function testWithdrawRollbackOnError(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('DB failure'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->service->withdraw('manual', 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    public function testWithdrawEmClosedRecovery(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
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
        $this->service->withdraw('manual', 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    // ──────────────── reverse ────────────────

    public function testReverseSingleSidedCredit(): void
    {
        $wallet = $this->createWallet(1, 20000, 'CNY');
        $voucher = $this->createAppliedDebitVoucher($wallet, 30000, 'rev');
        $this->voucherRepo->method('findByUuid')->with('voucher-uuid-rev')->willReturn($voucher);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willReturn($this->mockQuery());
        $this->em->method('refresh')->with($wallet)->willReturnCallback(function () use ($wallet) {
            $this->setWalletBalance($wallet, 50000);
        });
        $captured = null;
        $this->em->method('persist')->willReturnCallback(function (mixed $entity) use (&$captured): void {
            if ($entity instanceof Transaction) {
                $captured = $entity;
            }
        });
        $this->em->method('flush');

        $result = $this->service->reverse('voucher-uuid-rev', 'Admin correction');

        self::assertTrue($this->provider->reversed);
        self::assertSame(Voucher::STATUS_REVERSED, $result->getStatus());
        self::assertSame('Admin correction', $result->getMetadata()['reversalReason'] ?? null);
        self::assertNotNull($result->getReversalTransactionId());
        self::assertSame(50000, $wallet->getBalance());

        self::assertInstanceOf(Transaction::class, $captured);
        self::assertSame(Transaction::TYPE_DEBIT_REVERSAL, $captured->getType());
        self::assertSame(30000, $captured->getAmount());
        self::assertSame($wallet, $captured->getToWallet());
        self::assertNull($captured->getFromWallet());
        self::assertSame('withdraw-reverse-' . $voucher->getUuid(), $captured->getReferenceId());
        self::assertTrue($captured->isCompleted());
    }

    public function testReverseVoucherNotFound(): void
    {
        $this->voucherRepo->method('findByUuid')->with('missing')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Voucher "missing" not found.');
        $this->service->reverse('missing', 'reason');
    }

    public function testReverseRequiresAppliedStatus(): void
    {
        $wallet = $this->createWallet(1, 10000, 'CNY');
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_DEBIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-pending',
            10000,
            'CNY',
            'ref-pending',
            'admin',
        );
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be reversed from status "pending"');
        $this->service->reverse('voucher-uuid', 'reason');
    }

    public function testReverseRejectsCreditVoucher(): void
    {
        $wallet = $this->createWallet(1, 10000, 'CNY');
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-credit',
            10000,
            'CNY',
            'ref-credit',
            'admin',
        );
        $voucher->markApplied('credit-tx');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only debit (withdrawal) vouchers can be reversed.');
        $this->service->reverse('voucher-uuid', 'reason');
    }

    public function testReverseWalletNotFound(): void
    {
        $wallet = $this->createWallet(1, 20000, 'CNY');
        $voucher = $this->createAppliedDebitVoucher($wallet, 10000, 'missing-wallet');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet #1 not found');
        $this->service->reverse('voucher-uuid-missing-wallet', 'reason');
    }

    public function testReverseRollbackOnError(): void
    {
        $wallet = $this->createWallet(1, 20000, 'CNY');
        $voucher = $this->createAppliedDebitVoucher($wallet, 10000, 'rollback');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('DB failure'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->service->reverse('voucher-uuid-rollback', 'reason');
    }

    public function testReverseEmClosedRecovery(): void
    {
        $wallet = $this->createWallet(1, 20000, 'CNY');
        $voucher = $this->createAppliedDebitVoucher($wallet, 10000, 'em-closed');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
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
        $this->service->reverse('voucher-uuid-em-closed', 'reason');
    }
}
