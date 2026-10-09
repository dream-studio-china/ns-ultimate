<?php

namespace App\Tests\Integration\Wallet;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Integration\IntegrationKernelTestCase;
use App\Tests\Integration\DatabaseBootstrapTrait;

final class TransactionRepositoryTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private TransactionRepository $repo;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\\Wallet\\Entity\\Transaction')->execute();
        $this->em->createQuery('DELETE FROM App\\Wallet\\Entity\\Wallet')->execute();
        $this->em->createQuery('DELETE FROM App\\Identity\\Entity\\User')->execute();

        $this->repo = $this->em->getRepository(Transaction::class);
    }

    private function createUser(string $username = 'test'): User
    {
        $user = new User();
        $user->setEmail("$username@test.com");
        $user->setUsername($username);
        $user->setPassword('password');
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function createWallet(User $user, string $currency = 'USD', int $balance = 0): Wallet
    {
        $wallet = new Wallet($user, $currency);
        $this->em->persist($wallet);
        $this->em->flush();
        if ($balance > 0) {
            $this->em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
                ->setParameter('b', $balance)->setParameter('id', $wallet->getId())->execute();
            $this->em->refresh($wallet);
        }
        return $wallet;
    }

    private function createTransaction(Wallet $from, ?Wallet $to, int $amount, string $type, string $status = 'completed', ?string $referenceId = null): Transaction
    {
        $uuid = bin2hex(random_bytes(16));
        $tx = new Transaction($uuid, $amount, $type);
        $tx->setFromWallet($from);
        $tx->setToWallet($to);
        $tx->setReferenceId($referenceId);
        if ($status === 'completed') {
            $tx->markCompleted();
        } elseif ($status === 'failed') {
            $tx->markFailed();
        } else {
            $tx->setStatus($status);
        }
        $this->em->persist($tx);
        $this->em->flush();
        return $tx;
    }

    public function testFindByIdReturnsTransaction(): void
    {
        $user = $this->createUser('txfind');
        $from = $this->createWallet($user, 'USD', 5000);
        $to = $this->createWallet($this->createUser('txto'), 'USD');
        $tx = $this->createTransaction($from, $to, 1000, 'transfer');

        $found = $this->repo->findById($tx->getId());
        self::assertNotNull($found);
        self::assertSame($tx->getId(), $found->getId());
    }

    public function testFindByIdReturnsNull(): void
    {
        self::assertNull($this->repo->findById(999999));
    }

    public function testFindByUuid(): void
    {
        $user = $this->createUser('uuidfind');
        $from = $this->createWallet($user, 'USD', 5000);
        $to = $this->createWallet($this->createUser('uuidto'), 'USD');
        $tx = $this->createTransaction($from, $to, 1000, 'transfer');

        $found = $this->repo->findByUuid($tx->getUuid());
        self::assertNotNull($found);
        self::assertSame($tx->getUuid(), $found->getUuid());
    }

    public function testFindByUuidNotFound(): void
    {
        self::assertNull($this->repo->findByUuid('nonexistent-uuid'));
    }

    public function testFindByReferenceId(): void
    {
        $user = $this->createUser('refidfind');
        $from = $this->createWallet($user, 'USD', 5000);
        $to = $this->createWallet($this->createUser('refidto'), 'USD');
        $tx = $this->createTransaction($from, $to, 1000, 'transfer', 'completed', 'REF-123');

        $found = $this->repo->findByReferenceId('REF-123');
        self::assertNotNull($found);
        self::assertSame('REF-123', $found->getReferenceId());
    }

    public function testFindByReferenceIdNotFound(): void
    {
        self::assertNull($this->repo->findByReferenceId('REF-NONE'));
    }

    public function testFindByWallet(): void
    {
        $userA = $this->createUser('walletA');
        $userB = $this->createUser('walletB');
        $walletA = $this->createWallet($userA, 'USD', 10000);
        $walletB = $this->createWallet($userB, 'USD', 5000);

        $tx1 = $this->createTransaction($walletA, $walletB, 1000, 'transfer');
        $tx2 = $this->createTransaction($walletB, $walletA, 500, 'transfer');

        // WalletA has 2 transactions (1 outgoing, 1 incoming)
        $results = $this->repo->findByWallet($walletA->getId());
        self::assertCount(2, $results);
    }

    public function testFindByWalletEmpty(): void
    {
        $results = $this->repo->findByWallet(999999);
        self::assertIsArray($results);
        self::assertCount(0, $results);
    }

    public function testFindByWalletRespectsLimit(): void
    {
        $userA = $this->createUser('limitA');
        $userB = $this->createUser('limitB');
        $walletA = $this->createWallet($userA, 'USD', 50000);
        $walletB = $this->createWallet($userB, 'USD');

        for ($i = 0; $i < 5; $i++) {
            $this->createTransaction($walletA, $walletB, 1000, 'transfer');
        }

        $results = $this->repo->findByWallet($walletA->getId(), 2);
        self::assertCount(2, $results);
    }

    public function testFindPending(): void
    {
        $user = $this->createUser('pending');
        $from = $this->createWallet($user, 'USD', 5000);
        $to = $this->createWallet($this->createUser('pendingto'), 'USD');

        $tx = $this->createTransaction($from, $to, 1000, 'transfer', 'pending');

        $pending = $this->repo->findPending();
        self::assertNotEmpty($pending);
        self::assertSame('pending', $pending[0]->getStatus());
    }

    public function testFindPendingExcludesCompleted(): void
    {
        $user = $this->createUser('pendexcl');
        $from = $this->createWallet($user, 'USD', 5000);
        $to = $this->createWallet($this->createUser('pendto2'), 'USD');

        $this->createTransaction($from, $to, 1000, 'transfer', 'completed');
        $this->createTransaction($from, $to, 500, 'transfer', 'pending');

        $pending = $this->repo->findPending();
        self::assertCount(1, $pending);
        self::assertSame('pending', $pending[0]->getStatus());
    }

    public function testFindPendingEmpty(): void
    {
        $user = $this->createUser('pendempty');
        $from = $this->createWallet($user, 'USD', 5000);
        $to = $this->createWallet($this->createUser('pendto3'), 'USD');
        $this->createTransaction($from, $to, 1000, 'transfer', 'completed');

        $pending = $this->repo->findPending();
        self::assertIsArray($pending);
        self::assertCount(0, $pending);
    }

    public function testFindByWalletSortedByDate(): void
    {
        $userA = $this->createUser('sortA');
        $userB = $this->createUser('sortB');
        $walletA = $this->createWallet($userA, 'USD', 50000);
        $walletB = $this->createWallet($userB, 'USD');

        $tx1 = $this->createTransaction($walletA, $walletB, 1000, 'transfer');
        usleep(10000); // ensure different timestamps
        $tx2 = $this->createTransaction($walletA, $walletB, 2000, 'transfer');

        $results = $this->repo->findByWallet($walletA->getId());
        self::assertCount(2, $results);
        // Most recent first
        $created1 = $results[0]->getCreatedAt()->getTimestamp();
        $created2 = $results[1]->getCreatedAt()->getTimestamp();
        self::assertGreaterThanOrEqual($created2, $created1);
    }
}
