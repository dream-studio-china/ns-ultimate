<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wallet\Service;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class WalletServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private WalletRepository $walletRepo;
    private TransactionRepository $txRepo;
    private VoucherRepository $voucherRepo;
    private WalletService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->walletRepo = $this->createMock(WalletRepository::class);
        $this->txRepo = $this->createMock(TransactionRepository::class);
        $this->voucherRepo = $this->createMock(VoucherRepository::class);

        $this->em->method('getRepository')->with(Wallet::class)->willReturn($this->walletRepo);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback(
            fn(string $data, string $class, string $format, array $context) => $context['object_to_populate'] ?? null
        );

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new \Symfony\Component\Validator\ConstraintViolationList());

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn(string $id) => match ($id) {
                'doctrine.orm.entity_manager' => $this->em,
                'logger' => $this->createMock(LoggerInterface::class),
                'security.token_storage' => $this->createMock(TokenStorageInterface::class),
                'validator' => $validator,
                'serializer' => $serializer,
                default => null,
            }
        );
        $container->method('has')->willReturn(true);

        $this->service = new WalletService($container, $this->txRepo, $this->voucherRepo);
    }

    private function createWallet(int $id, int $balance): Wallet
    {
        $user = new User();
        $user->setEmail('test@example.com')->setUsername('test');

        $wallet = new Wallet($user, 'CNY');
        $refId = new \ReflectionProperty(Wallet::class, 'id');
        $refId->setValue($wallet, $id);
        $refBal = new \ReflectionProperty(Wallet::class, 'balance');
        $refBal->setValue($wallet, $balance);

        return $wallet;
    }

    // ───────────────── verifyBalance ─────────────────

    public function testVerifyBalanceMatches(): void
    {
        $this->walletRepo->method('getTotalBalanceByUnit')->willReturn([['currency' => 'CNY', 'total' => 500000]]);
        $this->voucherRepo->method('getBoundaryTotalByUnit')->willReturn(['CNY' => 500000]);
        $this->txRepo->method('getUnbackedDepositsByUnit')->willReturn([]);
        $this->walletRepo->method('count')->willReturn(4);

        $result = $this->service->verifyBalance();

        self::assertTrue($result['matches']);
        self::assertSame(4, $result['walletCount']);
        self::assertCount(1, $result['units']);
        $unit = $result['units'][0];
        self::assertSame('CNY', $unit['currency']);
        self::assertSame(500000, $unit['totalBalance']);
        self::assertSame(500000, $unit['voucherBoundary']);
        self::assertSame(0, $unit['unmatchedDeposits']);
        self::assertSame(500000, $unit['expected']);
        self::assertTrue($unit['matches']);
    }

    public function testVerifyBalanceMismatch(): void
    {
        $this->walletRepo->method('getTotalBalanceByUnit')->willReturn([['currency' => 'CNY', 'total' => 100000]]);
        $this->voucherRepo->method('getBoundaryTotalByUnit')->willReturn(['CNY' => 200000]);
        $this->txRepo->method('getUnbackedDepositsByUnit')->willReturn([]);
        $this->walletRepo->method('count')->willReturn(2);

        $result = $this->service->verifyBalance();

        self::assertFalse($result['matches']);
        $unit = $result['units'][0];
        self::assertSame(100000, $unit['totalBalance']);
        self::assertSame(200000, $unit['voucherBoundary']);
        self::assertFalse($unit['matches']);
    }

    public function testVerifyBalanceMatchesWithLegacyDeposits(): void
    {
        $this->walletRepo->method('getTotalBalanceByUnit')->willReturn([['currency' => 'CNY', 'total' => 600000]]);
        $this->voucherRepo->method('getBoundaryTotalByUnit')->willReturn(['CNY' => 500000]);
        $this->txRepo->method('getUnbackedDepositsByUnit')->willReturn(['CNY' => 100000]);
        $this->walletRepo->method('count')->willReturn(3);

        $result = $this->service->verifyBalance();

        self::assertTrue($result['matches']);
        $unit = $result['units'][0];
        self::assertSame(600000, $unit['totalBalance']);
        self::assertSame(100000, $unit['unmatchedDeposits']);
        self::assertSame(600000, $unit['expected']);
    }

    #[Group('low-value')]
    public function testVerifyBalanceZero(): void
    {
        $this->walletRepo->method('getTotalBalanceByUnit')->willReturn([]);
        $this->voucherRepo->method('getBoundaryTotalByUnit')->willReturn([]);
        $this->txRepo->method('getUnbackedDepositsByUnit')->willReturn([]);
        $this->walletRepo->method('count')->willReturn(0);

        $result = $this->service->verifyBalance();

        self::assertTrue($result['matches']);
        self::assertSame(0, $result['walletCount']);
        self::assertCount(0, $result['units']);
    }

    // ───────────────── reconcile ─────────────────

    #[Group('low-value')]
    public function testReconcileEmptyWallets(): void
    {
        $this->walletRepo->method('findAll')->willReturn([]);

        $result = $this->service->reconcile();

        self::assertSame(0, $result['reconciled']);
        self::assertCount(0, $result['adjustments']);
    }

    public function testReconcileBalancedWallet(): void
    {
        $wallet = $this->createWallet(1, 500000);
        $this->walletRepo->method('findAll')->willReturn([$wallet]);
        $this->txRepo->method('getExpectedBalance')->with(1)->willReturn(500000);

        $result = $this->service->reconcile();

        self::assertSame(0, $result['reconciled']);
        self::assertCount(0, $result['adjustments']);
    }

    public function testReconcileExcessBalanceByDeposit(): void
    {
        $wallet = $this->createWallet(1, 500000);
        $this->walletRepo->method('findAll')->willReturn([$wallet]);
        $this->txRepo->method('getExpectedBalance')->with(1)->willReturn(0);

        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Transaction::class));
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->reconcile();

        self::assertSame(1, $result['reconciled']);
        self::assertCount(1, $result['adjustments']);
        self::assertSame(500000, $result['adjustments'][0]['diff']);
        self::assertSame('deposited', $result['adjustments'][0]['action']);
    }

    public function testReconcileNegativeDiffSkipped(): void
    {
        $wallet = $this->createWallet(1, 0);
        $this->walletRepo->method('findAll')->willReturn([$wallet]);
        $this->txRepo->method('getExpectedBalance')->with(1)->willReturn(100000);

        $result = $this->service->reconcile();

        self::assertSame(0, $result['reconciled']);
        self::assertCount(1, $result['adjustments']);
        self::assertSame(-100000, $result['adjustments'][0]['diff']);
        self::assertSame('skipped_negative', $result['adjustments'][0]['action']);
    }

    public function testReconcileIdempotent(): void
    {
        $wallet = $this->createWallet(1, 500000);
        $this->walletRepo->method('findAll')->willReturn([$wallet]);
        $this->txRepo->method('getExpectedBalance')->with(1)
            ->willReturnOnConsecutiveCalls(0, 500000);

        // First run: diff +500K, creates adjustment
        $r1 = $this->service->reconcile();
        self::assertSame(1, $r1['reconciled']);
        self::assertSame('deposited', $r1['adjustments'][0]['action']);

        // Second run: now expected matches (adjustment counted), no more to reconcile
        $r2 = $this->service->reconcile();
        self::assertSame(0, $r2['reconciled']);
        self::assertCount(0, $r2['adjustments']);
    }

    public function testReconcileMultipleWallets(): void
    {
        $w1 = $this->createWallet(1, 300000);
        $w2 = $this->createWallet(2, 0);
        $w3 = $this->createWallet(3, 100000);
        $this->walletRepo->method('findAll')->willReturn([$w1, $w2, $w3]);

        $this->txRepo->method('getExpectedBalance')->willReturnCallback(
            fn(int $id) => match ($id) {
                1 => 0,       // excess 300K → deposit
                2 => 200000,  // missing 200K → skipped
                3 => 100000,  // balanced → no action
                default => 0,
            }
        );

        $result = $this->service->reconcile();

        self::assertSame(1, $result['reconciled']);
        self::assertCount(2, $result['adjustments']);
    }

    #[Group('low-value')]
    public function testReconcileSkipsNonWalletEntities(): void
    {
        $wallet = $this->createWallet(1, 500000);
        $this->walletRepo->method('findAll')->willReturn([$wallet, new \stdClass()]);
        $this->txRepo->method('getExpectedBalance')->with(1)->willReturn(0);

        $result = $this->service->reconcile();

        self::assertSame(1, $result['reconciled']);
    }

    #[Group('low-value')]
    public function testReconcileSkipsWalletWithoutId(): void
    {
        $user = new User();
        $wallet = new Wallet($user, 'CNY');
        // No id set — constructor defaults to null
        $this->walletRepo->method('findAll')->willReturn([$wallet]);

        $this->txRepo->expects(self::never())->method('getExpectedBalance');

        $result = $this->service->reconcile();

        self::assertSame(0, $result['reconciled']);
    }
}
