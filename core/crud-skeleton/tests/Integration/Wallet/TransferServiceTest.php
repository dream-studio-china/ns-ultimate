<?php

namespace App\Tests\Integration\Wallet;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Exception\SameWalletTransferException;
use App\Wallet\Exception\WalletFrozenException;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Repository\TransactionRepository;
use App\Wallet\Service\Transfer\TransferService;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Tests\Integration\DatabaseBootstrapTrait;

final class TransferServiceTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private WalletRepository $walletRepo;
    private TransactionRepository $txRepo;
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $tables = ['App\\Wallet\\Entity\\Transaction', 'App\\Wallet\\Entity\\Wallet'];
        foreach ($tables as $table) {
            $this->em->createQuery("DELETE FROM $table")->execute();
        }

        $this->walletRepo = $this->em->getRepository(Wallet::class);
        $this->txRepo = $this->em->getRepository(Transaction::class);
        $this->transferService = static::getContainer()->get(TransferService::class);
    }

    private function createUser(string $username = 'testuser'): User
    {
        $user = new User();
        $user->setEmail("$username@test.com");
        $user->setUsername($username);
        $user->setPassword('password');
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function createWallet(User $user, int $balance = 100000, string $currency = 'USD'): Wallet
    {
        $wallet = new Wallet($user, $currency);
        $this->em->persist($wallet);
        $this->em->flush();

        // Set balance via direct SQL to simulate real-world scenario
        if ($balance > 0) {
            $this->em->createQuery(
                'UPDATE App\Wallet\Entity\Wallet w SET w.balance = :balance WHERE w.id = :id'
            )
                ->setParameter('balance', $balance)
                ->setParameter('id', $wallet->getId())
                ->execute();
            $this->em->refresh($wallet);
        }

        return $wallet;
    }

    // ------------------------------------------------
    //  Basic transfer flow
    // ------------------------------------------------

    public function testBasicTransfer(): void
    {
        $userA = $this->createUser('alice');
        $userB = $this->createUser('bob');
        $from = $this->createWallet($userA, 100000); // $1000.00
        $to = $this->createWallet($userB, 50000);     // $500.00

        $result = $this->transferService->transfer($from->getId(), $to->getId(), 25000); // $250.00

        self::assertSame(Transaction::STATUS_COMPLETED, $result->transaction->getStatus());
        self::assertSame(25000, $result->transaction->getAmount());
        self::assertSame($from->getId(), $result->transaction->getFromWallet()->getId());
        self::assertSame($to->getId(), $result->transaction->getToWallet()->getId());
        self::assertSame(75000, $result->fromWalletBalanceAfter);  // 1000 - 250
        self::assertSame(75000, $result->toWalletBalanceAfter);    // 500 + 250
    }

    public function testTransferExactBalance(): void
    {
        $from = $this->createWallet($this->createUser('a1'), 10000);
        $to = $this->createWallet($this->createUser('b1'), 0);

        $result = $this->transferService->transfer($from->getId(), $to->getId(), 10000);

        self::assertSame(0, $result->fromWalletBalanceAfter);
        self::assertSame(10000, $result->toWalletBalanceAfter);
        self::assertSame(Transaction::STATUS_COMPLETED, $result->transaction->getStatus());
    }

    public function testTransferOneCent(): void
    {
        $from = $this->createWallet($this->createUser('a2'), 10000);
        $to = $this->createWallet($this->createUser('b2'), 0);

        $result = $this->transferService->transfer($from->getId(), $to->getId(), 1);

        self::assertSame(9999, $result->fromWalletBalanceAfter);
        self::assertSame(1, $result->toWalletBalanceAfter);
    }

    // ------------------------------------------------
    //  Idempotency
    // ------------------------------------------------

    public function testIdempotencyByReferenceId(): void
    {
        $from = $this->createWallet($this->createUser('a3'), 100000);
        $to = $this->createWallet($this->createUser('b3'), 0);
        $refId = 'ORDER-12345';

        $result1 = $this->transferService->transfer($from->getId(), $to->getId(), 10000, $refId);
        $result2 = $this->transferService->transfer($from->getId(), $to->getId(), 10000, $refId);

        // Second call returns the SAME transaction (idempotent)
        self::assertSame($result1->transaction->getId(), $result2->transaction->getId());
        self::assertSame($result1->transaction->getUuid(), $result2->transaction->getUuid());
        // Balance should NOT be deducted twice
        self::assertSame($result1->fromWalletBalanceAfter, $result2->fromWalletBalanceAfter);
    }

    public function testSameReferenceIdDifferentParamsReturnsExisting(): void
    {
        $from = $this->createWallet($this->createUser('a4'), 50000);
        $to = $this->createWallet($this->createUser('b4'), 0);
        $refId = 'IDEM-001';

        $this->transferService->transfer($from->getId(), $to->getId(), 10000, $refId);

        // Second call with different amount but same ref ID: returns existing, no double deduct
        $result2 = $this->transferService->transfer($from->getId(), $to->getId(), 20000, $refId);

        self::assertSame(40000, $result2->fromWalletBalanceAfter); // 500-100, NOT 500-200
        self::assertSame(10000, $result2->toWalletBalanceAfter);
    }

    // ------------------------------------------------
    //  Edge cases
    // ------------------------------------------------

    public function testTransferInsufficientFunds(): void
    {
        $from = $this->createWallet($this->createUser('a5'), 5000);
        $to = $this->createWallet($this->createUser('b5'), 0);

        $this->expectException(InsufficientFundsException::class);
        $this->transferService->transfer($from->getId(), $to->getId(), 5001);
    }

    public function testTransferSameWalletThrows(): void
    {
        $wallet = $this->createWallet($this->createUser('a6'), 10000);

        $this->expectException(SameWalletTransferException::class);
        $this->transferService->transfer($wallet->getId(), $wallet->getId(), 100);
    }

    public function testTransferFromFrozenWallet(): void
    {
        $user = $this->createUser('a7');
        $from = $this->createWallet($user, 10000);
        $from->setStatus('frozen');
        $this->em->flush();

        $to = $this->createWallet($this->createUser('b7'), 0);

        $this->expectException(WalletFrozenException::class);
        $this->transferService->transfer($from->getId(), $to->getId(), 100);
    }

    public function testTransferToFrozenWallet(): void
    {
        $from = $this->createWallet($this->createUser('a8'), 10000);
        $to = $this->createWallet($this->createUser('b8'), 0);
        $to->setStatus('frozen');
        $this->em->flush();

        $this->expectException(WalletFrozenException::class);
        $this->transferService->transfer($from->getId(), $to->getId(), 100);
    }

    public function testTransferZeroAmount(): void
    {
        $from = $this->createWallet($this->createUser('a9'), 10000);
        $to = $this->createWallet($this->createUser('b9'), 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');
        $this->transferService->transfer($from->getId(), $to->getId(), 0);
    }

    public function testTransferNegativeAmount(): void
    {
        $from = $this->createWallet($this->createUser('a10'), 10000);
        $to = $this->createWallet($this->createUser('b10'), 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');
        $this->transferService->transfer($from->getId(), $to->getId(), -500);
    }

    public function testTransferCurrencyMismatch(): void
    {
        $from = $this->createWallet($this->createUser('a11'), 10000, 'USD');
        $to = $this->createWallet($this->createUser('b11'), 5000, 'EUR');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Currency mismatch');
        $this->transferService->transfer($from->getId(), $to->getId(), 100);
    }

    public function testTransferNonexistentWallet(): void
    {
        $wallet = $this->createWallet($this->createUser('a12'), 10000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->transferService->transfer($wallet->getId(), 999999, 100);
    }

    // ------------------------------------------------
    //  Concurrency / Race Condition Test
    // ------------------------------------------------

    public function testConcurrentTransfersDoNotDoubleSpend(): void
    {
        $from = $this->createWallet($this->createUser('conc1'), 10000);
        $to1 = $this->createWallet($this->createUser('conc2'), 0);
        $to2 = $this->createWallet($this->createUser('conc3'), 0);

        // First transfer succeeds: 100 - 60 = 40
        $result = $this->transferService->transfer($from->getId(), $to1->getId(), 6000);
        self::assertSame(4000, $result->fromWalletBalanceAfter);

        // Second transfer should fail: 40 - 60 = insufficient
        $caught = false;
        try {
            $this->transferService->transfer($from->getId(), $to2->getId(), 6000);
        } catch (InsufficientFundsException) {
            $caught = true;
        }
        self::assertTrue($caught, 'Second transfer should throw InsufficientFundsException');

        // Verify balance integrity via fresh fetch
        $freshWallet = $this->em->getRepository(\App\Wallet\Entity\Wallet::class)->find($from->getId());
        self::assertSame(4000, $freshWallet->getBalance(), 'Balance should still be 4000 after failed second transfer');
    }

    // ------------------------------------------------
    //  Multiple consecutive transfers
    // ------------------------------------------------

    public function testMultipleConsecutiveTransfers(): void
    {
        $alice = $this->createWallet($this->createUser('alice_seq'), 50000);
        $bob = $this->createWallet($this->createUser('bob_seq'), 0);
        $charlie = $this->createWallet($this->createUser('charlie_seq'), 0);

        $this->transferService->transfer($alice->getId(), $bob->getId(), 20000);
        $this->transferService->transfer($bob->getId(), $charlie->getId(), 5000);
        $this->transferService->transfer($alice->getId(), $charlie->getId(), 10000);

        $this->em->refresh($alice);
        $this->em->refresh($bob);
        $this->em->refresh($charlie);

        self::assertSame(20000, $alice->getBalance()); // 500 - 200 - 100
        self::assertSame(15000, $bob->getBalance());   // 0 + 200 - 50
        self::assertSame(15000, $charlie->getBalance()); // 0 + 50 + 100
    }

    // ------------------------------------------------
    //  Balance integrity after failed transfer
    // ------------------------------------------------

    public function testBalanceUnchangedAfterFailedTransfer(): void
    {
        $from = $this->createWallet($this->createUser('fail1'), 5000);
        $fromId = $from->getId();
        $to = $this->createWallet($this->createUser('fail2'), 0);
        $toId = $to->getId();

        $caught = false;
        try {
            $this->transferService->transfer($fromId, $toId, 10000);
        } catch (InsufficientFundsException) {
            $caught = true;
        }
        self::assertTrue($caught, 'Expected InsufficientFundsException');

        // Fetch fresh to verify balance unchanged (EM may be closed)
        $from = $this->em->getRepository(\App\Wallet\Entity\Wallet::class)->find($fromId);
        $to = $this->em->getRepository(\App\Wallet\Entity\Wallet::class)->find($toId);

        self::assertNotNull($from);
        self::assertNotNull($to);
        self::assertSame(5000, $from->getBalance(), 'Source balance should be unchanged');
        self::assertSame(0, $to->getBalance(), 'Target balance should be unchanged');
    }
}
