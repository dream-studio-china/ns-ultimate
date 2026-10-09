<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service\Deposit;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Entity\Voucher;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Service\Deposit\DepositProviderInterface;
use App\Wallet\Service\Deposit\DepositProviderRegistry;
use App\Wallet\Service\Deposit\DepositService;
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

final class RecordingDepositProvider implements DepositProviderInterface
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
final class DepositServiceTest extends TestCase
{
    private ManagerRegistry $registry;
    private EntityManagerInterface $em;
    private Connection $connection;
    private VoucherRepository $voucherRepo;
    private DepositProviderRegistry $providerRegistry;
    private WalletRepository $walletRepo;
    private RecordingDepositProvider $provider;
    private DepositService $service;

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
        $this->provider = new RecordingDepositProvider(Voucher::VOUCHER_TYPE_MANUAL);
        $this->providerRegistry = new DepositProviderRegistry([$this->provider]);

        $this->service = new DepositService(
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

    private function createAppliedCreditVoucher(Wallet $wallet, int $amount, string $uuid = 'voucher-uuid'): Voucher
    {
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_CREDIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-' . $uuid,
            $amount,
            $wallet->getCurrency(),
            'ref-' . $uuid,
            'admin',
        );
        $voucher->markApplied('credit-tx-' . $uuid);
        return $voucher;
    }

    private function mockQuery(): Query
    {
        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('execute')->willReturn(1);
        return $query;
    }

    // ──────────────── deposit ────────────────

    public function testDepositHappyPath(): void
    {
        $wallet = $this->createWallet(1, 0, 'CNY');
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

        $voucher = $this->service->deposit(
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-1',
            1,
            50000,
            'CNY',
            'ref-dep-1',
            'admin',
            'Manual funding',
        );

        self::assertTrue($this->provider->authorized);
        self::assertSame(Voucher::STATUS_APPLIED, $voucher->getStatus());
        self::assertSame(Voucher::DIRECTION_CREDIT, $voucher->getDirection());
        self::assertSame(Voucher::FUND_SOURCE_EXTERNAL, $voucher->getFundSource());
        self::assertSame($wallet, $voucher->getWallet());
        self::assertSame(50000, $voucher->getAmount());
        self::assertSame('admin', $voucher->getCreatedBy());
        self::assertNotNull($voucher->getTransactionId());
        self::assertSame(50000, $wallet->getBalance());

        self::assertInstanceOf(Transaction::class, $captured);
        self::assertSame(Transaction::TYPE_DEPOSIT, $captured->getType());
        self::assertSame(50000, $captured->getAmount());
        self::assertSame($wallet, $captured->getToWallet());
        self::assertNull($captured->getFromWallet());
        self::assertStringStartsWith('deposit-', $captured->getReferenceId());
        self::assertTrue($captured->isCompleted());
    }

    public function testDepositAmountNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Deposit amount must be positive');
        $this->service->deposit('manual', 'm1', 1, 0, 'CNY', 'ref', 'admin');
    }

    public function testDepositRequiresReferenceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference id is required');
        $this->service->deposit('manual', 'm1', 1, 100, 'CNY', '', 'admin');
    }

    public function testDepositIdempotent(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $existing = $this->createAppliedCreditVoucher($wallet, 50000, 'same');
        $this->voucherRepo->method('findByReferenceId')->with('ref-dep-1')->willReturn($existing);

        $result = $this->service->deposit('manual', 'other', 1, 99999, 'CNY', 'ref-dep-1', 'admin');

        self::assertSame($existing, $result);
    }

    public function testDepositRejectsDuplicateVoucherSource(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $existing = $this->createAppliedCreditVoucher($wallet, 50000, 'dup');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->with('manual', 'manual-1')->willReturn($existing);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already processed');
        $this->service->deposit('manual', 'manual-1', 1, 100, 'CNY', 'ref-new', 'admin');
    }

    public function testDepositRejectsUnsupportedVoucherType(): void
    {
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported voucher type "transfer".');
        $this->service->deposit('transfer', 'x', 1, 100, 'CNY', 'ref-t', 'admin');
    }

    public function testDepositDeniedByProviderPermission(): void
    {
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->provider->permitException = new AccessDeniedException('Manual voucher type is admin-only.');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Manual voucher type is admin-only.');
        $this->service->deposit(Voucher::VOUCHER_TYPE_MANUAL, 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    public function testDepositConcurrentDuplicateIsIdempotent(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $existing = $this->createAppliedCreditVoucher($wallet, 50000, 'race');

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

        $result = $this->service->deposit(
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-race',
            1,
            50000,
            'CNY',
            'ref-race',
            'admin',
        );

        self::assertSame($existing, $result);
    }

    public function testDepositWalletNotFound(): void
    {
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target wallet #999 not found');
        $this->service->deposit('manual', 'm1', 999, 100, 'CNY', 'ref', 'admin');
    }

    public function testDepositWalletFrozen(): void
    {
        $wallet = $this->createWallet(1, 0, 'CNY', 'frozen');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(WalletFrozenException::class);
        $this->service->deposit('manual', 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    public function testDepositCurrencyMismatch(): void
    {
        $wallet = $this->createWallet(1, 0, 'CNY');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency mismatch');
        $this->service->deposit('manual', 'm1', 1, 100, 'USD', 'ref', 'admin');
    }

    public function testDepositRollbackOnError(): void
    {
        $wallet = $this->createWallet(1, 0, 'CNY');
        $this->voucherRepo->method('findByReferenceId')->willReturn(null);
        $this->voucherRepo->method('findByVoucherSource')->willReturn(null);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('DB failure'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->service->deposit('manual', 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    // ──────────────── reverse ────────────────

    public function testReverseSingleSidedDebit(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $voucher = $this->createAppliedCreditVoucher($wallet, 30000, 'rev');
        $this->voucherRepo->method('findByUuid')->with('voucher-uuid-rev')->willReturn($voucher);
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

        $result = $this->service->reverse('voucher-uuid-rev', 'Admin correction');

        self::assertTrue($this->provider->reversed);
        self::assertSame(Voucher::STATUS_REVERSED, $result->getStatus());
        self::assertSame('Admin correction', $result->getMetadata()['reversalReason'] ?? null);
        self::assertNotNull($result->getReversalTransactionId());
        self::assertSame(20000, $wallet->getBalance());

        self::assertInstanceOf(Transaction::class, $captured);
        self::assertSame(Transaction::TYPE_CREDIT_REVERSAL, $captured->getType());
        self::assertSame(30000, $captured->getAmount());
        self::assertSame($wallet, $captured->getFromWallet());
        self::assertNull($captured->getToWallet());
        self::assertSame('deposit-reverse-' . $voucher->getUuid(), $captured->getReferenceId());
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
            Voucher::DIRECTION_CREDIT,
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

    public function testReverseRejectsDebitVoucher(): void
    {
        $wallet = $this->createWallet(1, 10000, 'CNY');
        $voucher = new Voucher(
            $wallet,
            Voucher::DIRECTION_DEBIT,
            Voucher::FUND_SOURCE_EXTERNAL,
            Voucher::VOUCHER_TYPE_MANUAL,
            'manual-debit',
            10000,
            'CNY',
            'ref-debit',
            'admin',
        );
        $voucher->markApplied('debit-tx');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only credit (deposit) vouchers can be reversed.');
        $this->service->reverse('voucher-uuid', 'reason');
    }

    public function testReverseInsufficientAvailable(): void
    {
        $wallet = $this->createWallet(1, 10000, 'CNY');
        $voucher = $this->createAppliedCreditVoucher($wallet, 30000, 'insuff');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);

        $this->expectException(InsufficientFundsException::class);
        $this->service->reverse('voucher-uuid-insuff', 'reason');
    }

    public function testReverseRollbackOnError(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $voucher = $this->createAppliedCreditVoucher($wallet, 10000, 'rollback');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn($wallet);
        $this->em->method('createQuery')->willThrowException(new \RuntimeException('DB failure'));

        $this->connection->expects(self::once())->method('isTransactionActive');
        $this->em->expects(self::once())->method('rollback');

        $this->expectException(\RuntimeException::class);
        $this->service->reverse('voucher-uuid-rollback', 'reason');
    }

    public function testReverseWalletNotFound(): void
    {
        $wallet = $this->createWallet(1, 10000, 'CNY');
        $voucher = $this->createAppliedCreditVoucher($wallet, 10000, 'missing-wallet');
        $this->voucherRepo->method('findByUuid')->willReturn($voucher);
        $this->walletRepo->method('findByIdForUpdate')->with(1)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet #1 not found');
        $this->service->reverse('voucher-uuid-missing-wallet', 'reason');
    }

    public function testDepositEmClosedRecovery(): void
    {
        $wallet = $this->createWallet(1, 0, 'CNY');
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
        $this->service->deposit('manual', 'm1', 1, 100, 'CNY', 'ref', 'admin');
    }

    public function testReverseEmClosedRecovery(): void
    {
        $wallet = $this->createWallet(1, 50000, 'CNY');
        $voucher = $this->createAppliedCreditVoucher($wallet, 10000, 'em-closed');
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
