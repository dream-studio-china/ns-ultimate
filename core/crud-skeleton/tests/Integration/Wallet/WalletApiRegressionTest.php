<?php

namespace App\Tests\Integration\Wallet;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Wallet\Entity\Wallet;
use App\Wallet\Entity\Transaction;
use App\Wallet\Entity\Voucher;
use App\Wallet\Service\Deposit\DepositService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class WalletApiRegressionTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $tables = [
            'App\\Wallet\\Entity\\Voucher',
            'App\\Wallet\\Entity\\Transaction',
            'App\\Wallet\\Entity\\Wallet',
        ];
        foreach ($tables as $table) {
            $em->createQuery("DELETE FROM $table")->execute();
        }
        self::ensureKernelShutdown();
    }

    private function createTestUser(EntityManagerInterface $em, string $username): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail("$username@test.com");
        $user->setUsername($username);
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function createClientForUser(User $user): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $tokenManager = $client->getContainer()->get(TokenManager::class);

        $client->setServerParameters(['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenManager->createAccessToken($user)]);

        return $client;
    }

    // ------------------------------------------------
    //  Wallet CRUD via Manage API
    // ------------------------------------------------

    public function testCreateAndListWallets(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = $this->createTestUser($em, 'walletuser');

        // Create wallet
        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $user->getId(), 'currency' => 'USD', 'label' => 'My USD Wallet',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $created['code']);
        $walletId = $created['data']['id'];

        // List
        $client->request('GET', '/api/v1/manage/wallets');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($list['data']);
        self::assertCount(1, $list['data']);
    }

    public function testAppWalletsAndTransactionsAreScopedToCurrentUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createTestUser($em, 'app_wallet_alice');
        $bob = $this->createTestUser($em, 'app_wallet_bob');
        $aliceWallet = new Wallet($alice, 'USD');
        $bobWallet = new Wallet($bob, 'USD');
        $em->persist($aliceWallet);
        $em->persist($bobWallet);
        $em->flush();

        $ownTx = new Transaction('app-wallet-own-' . bin2hex(random_bytes(6)), 1200, Transaction::TYPE_DEPOSIT);
        $ownTx->setToWallet($aliceWallet)->markCompleted();
        $otherTx = new Transaction('app-wallet-other-' . bin2hex(random_bytes(6)), 3400, Transaction::TYPE_DEPOSIT);
        $otherTx->setToWallet($bobWallet)->markCompleted();
        $em->persist($ownTx);
        $em->persist($otherTx);
        $em->flush();

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :balance WHERE w.id = :id')
            ->setParameter('balance', 1200)
            ->setParameter('id', $aliceWallet->getId())
            ->execute();
        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :balance WHERE w.id = :id')
            ->setParameter('balance', 3400)
            ->setParameter('id', $bobWallet->getId())
            ->execute();

        $client = $this->createClientForUser($alice);

        $client->request('GET', '/api/v1/app/wallets');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $wallets = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $wallets['data']);
        self::assertSame($aliceWallet->getId(), $wallets['data'][0]['id']);

        $client->request('GET', '/api/v1/app/wallets/' . $bobWallet->getId());
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/app/transactions');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $transactions = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $transactions['data']);
        self::assertSame($ownTx->getId(), $transactions['data'][0]['id']);

        $client->request('GET', '/api/v1/app/transactions/' . $otherTx->getId());
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/app/wallets/balance');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $balance = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($balance['data']['matches']);
        self::assertSame(1, $balance['data']['walletCount']);
        self::assertCount(1, $balance['data']['units']);
        $unit = $balance['data']['units'][0];
        self::assertSame('USD', $unit['currency']);
        self::assertSame(1200, $unit['totalBalance']);
        self::assertSame(0, $unit['voucherBoundary']);
        self::assertSame(1200, $unit['unmatchedDeposits']);
        self::assertTrue($unit['matches']);
    }

    public function testManualDepositAndReverseViaEndpoint(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = $this->createTestUser($em, 'deposit_api_user');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $user->getId(), 'currency' => 'CNY',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $walletId = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['id'];

        // Manual deposit (voucher-backed)
        $client->request('POST', '/api/v1/manage/vouchers/deposit', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'walletId' => $walletId, 'amount' => 50000, 'currency' => 'CNY',
            'referenceId' => 'INT-DEP-1', 'reason' => 'integration deposit',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $deposit = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame('applied', $deposit['status']);
        self::assertSame('manual', $deposit['voucherType']);
        $uuid = $deposit['uuid'];

        // Admin voucher list + detail expose the boundary record
        $client->request('GET', '/api/v1/manage/vouchers/' . $uuid);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $voucher = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame('manual', $voucher['voucherType']);
        self::assertSame(50000, $voucher['amount']);
        self::assertSame('INT-DEP-1', $voucher['referenceId']);

        $client->request('GET', '/api/v1/manage/vouchers');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $vouchers = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertNotEmpty($vouchers);

        // Append an immutable annotation (voucher-comment resource) and read it back
        $voucherEntity = $em->getRepository(Voucher::class)->findOneBy(['uuid' => $uuid]);
        self::assertNotNull($voucherEntity);

        $client->request('POST', '/api/v1/manage/voucher-comments', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'voucher' => $voucherEntity->getId(), 'text' => 'Finance note: ticket #INT-9',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/manage/voucher-comments');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $comments = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertNotEmpty($comments);
        self::assertSame('Finance note: ticket #INT-9', $comments[0]['text']);

        $client->request('GET', '/api/v1/manage/wallets/' . $walletId);
        $wallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(50000, $wallet['balance']);

        // Duplicate referenceId is idempotent
        $client->request('POST', '/api/v1/manage/vouchers/deposit', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'walletId' => $walletId, 'amount' => 50000, 'currency' => 'CNY', 'referenceId' => 'INT-DEP-1',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // Reverse returns funds
        $client->request('POST', '/api/v1/manage/vouchers/' . $uuid . '/reverse', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'reason' => 'integration revert',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $reversed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame('reversed', $reversed['status']);

        $client->request('GET', '/api/v1/manage/wallets/' . $walletId);
        $wallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(0, $wallet['balance']);
    }

    public function testAppVouchersScopedToCurrentUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $alice = $this->createTestUser($em, 'voucher_alice');
        $bob = $this->createTestUser($em, 'voucher_bob');
        $aliceWallet = new Wallet($alice, 'CNY');
        $bobWallet = new Wallet($bob, 'CNY');
        $em->persist($aliceWallet);
        $em->persist($bobWallet);
        $em->flush();

        $depositService = $client->getContainer()->get(DepositService::class);
        $depositService->deposit(
            Voucher::VOUCHER_TYPE_MANUAL,
            'vouch-a',
            (int) $aliceWallet->getId(),
            50000,
            'CNY',
            'vouch-ref-a',
            'system',
        );
        $bobVoucher = $depositService->deposit(
            Voucher::VOUCHER_TYPE_MANUAL,
            'vouch-b',
            (int) $bobWallet->getId(),
            30000,
            'CNY',
            'vouch-ref-b',
            'system',
        );

        $client = $this->createClientForUser($alice);
        $client->request('GET', '/api/v1/app/vouchers');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['data']);
        self::assertSame('vouch-ref-a', $data['data'][0]['referenceId']);

        // Alice must not be able to view Bob's voucher.
        $client->request('GET', '/api/v1/app/vouchers/' . $bobVoucher->getUuid());
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAppTransferFromOwnWallet(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $alice = $this->createTestUser($em, 'app_tx_alice');
        $bob = $this->createTestUser($em, 'app_tx_bob');
        $aliceWallet = new Wallet($alice, 'CNY');
        $bobWallet = new Wallet($bob, 'CNY');
        $em->persist($aliceWallet);
        $em->persist($bobWallet);
        $em->flush();

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 50000)->setParameter('id', $aliceWallet->getId())->execute();

        // Alice transfers from her own wallet
        $client = $this->createClientForUser($alice);
        $client->request('POST', '/api/v1/app/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $aliceWallet->getId(), 'toWalletId' => $bobWallet->getId(), 'amount' => 10000,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(40000, $data['fromWalletBalanceAfter']);
        self::assertSame(10000, $data['toWalletBalanceAfter']);

        // Alice cannot transfer out of Bob's wallet
        $client->request('POST', '/api/v1/app/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $bobWallet->getId(), 'toWalletId' => $aliceWallet->getId(), 'amount' => 100,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testWalletCreateDuplicateCurrencyFails(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = $this->createTestUser($em, 'dupuser');

        // First wallet
        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $user->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // Second wallet with same user+currency
        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $user->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        self::assertNotSame(201, $client->getResponse()->getStatusCode());
    }

    public function testWalletUpdateStatus(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = $this->createTestUser($em, 'statususer');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $user->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('PUT', '/api/v1/manage/wallets/' . $id, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'status' => 'frozen',
        ], JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('frozen', $updated['data']['status']);
    }

    public function testWalletDelete(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = $this->createTestUser($em, 'deluser');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $user->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $created['data']['id'];

        $client->request('DELETE', '/api/v1/manage/wallets/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/manage/wallets/' . $id);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------
    //  Transfer via API
    // ------------------------------------------------

    public function testTransferApiSuccess(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createTestUser($em, 'tf_alice');
        $bob = $this->createTestUser($em, 'tf_bob');

        // Create wallets and set balances
        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $alice->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $aliceWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $aliceId = $aliceWallet['data']['id'];

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $bob->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $bobWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $bobId = $bobWallet['data']['id'];

        // Seed balance via direct SQL (manage API doesn't set balance)
        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 100000)->setParameter('id', $aliceId)->execute();

        // Transfer
        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $aliceId, 'toWalletId' => $bobId, 'amount' => 25000, 'referenceId' => 'TX-001',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $result = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['code']);
        self::assertSame('completed', $result['data']['status']);
        self::assertSame(25000, $result['data']['amount']);
        self::assertSame(250.0, $result['data']['amountFloat']);
        self::assertSame(75000, $result['data']['fromWalletBalanceAfter']);
        self::assertSame(25000, $result['data']['toWalletBalanceAfter']);
    }

    public function testTransferApiInsufficientFunds(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createTestUser($em, 'tf_nofunds');
        $bob = $this->createTestUser($em, 'tf_nofunds2');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $alice->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $aliceWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $bob->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $bobWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $aliceWallet['data']['id'], 'toWalletId' => $bobWallet['data']['id'], 'amount' => 999999,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTransferApiMissingFields(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => 1,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTransferApiSameWallet(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $user = $this->createTestUser($em, 'samewallet');
        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $user->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $wallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $id = $wallet['data']['id'];

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 10000)->setParameter('id', $id)->execute();

        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $id, 'toWalletId' => $id, 'amount' => 100,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTransferApiFrozenWallet(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createTestUser($em, 'frozen_alice');
        $bob = $this->createTestUser($em, 'frozen_bob');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $alice->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $aliceWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $aliceId = $aliceWallet['data']['id'];

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $bob->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $bobWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $bobId = $bobWallet['data']['id'];

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 10000)->setParameter('id', $aliceId)->execute();

        // Freeze alice's wallet
        $client->request('PUT', '/api/v1/manage/wallets/' . $aliceId, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'status' => 'frozen',
        ], JSON_THROW_ON_ERROR));

        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $aliceId, 'toWalletId' => $bobId, 'amount' => 100,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testTransferApiIdempotency(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createTestUser($em, 'idem_alice');
        $bob = $this->createTestUser($em, 'idem_bob');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $alice->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $aliceWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $aliceId = $aliceWallet['data']['id'];

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'user' => $bob->getId(), 'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $bobWallet = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $bobId = $bobWallet['data']['id'];

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 100000)->setParameter('id', $aliceId)->execute();

        $payload = json_encode([
            'fromWalletId' => $aliceId, 'toWalletId' => $bobId, 'amount' => 30000, 'referenceId' => 'IDEM-API-001',
        ], JSON_THROW_ON_ERROR);

        // First transfer
        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $first = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Duplicate transfer with same referenceId
        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $second = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Should return same transaction, balances unchanged (no double spend)
        self::assertSame($first['data']['transactionId'], $second['data']['transactionId']);
        self::assertSame($first['data']['fromWalletBalanceAfter'], $second['data']['fromWalletBalanceAfter']);
    }

    // ------------------------------------------------
    //  Transaction listing
    // ------------------------------------------------

    public function testTransactionList(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createTestUser($em, 'txlist_alice');
        $bob = $this->createTestUser($em, 'txlist_bob');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['user' => $alice->getId(), 'currency' => 'USD'], JSON_THROW_ON_ERROR));
        $aw = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['user' => $bob->getId(), 'currency' => 'USD'], JSON_THROW_ON_ERROR));
        $bw = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 50000)->setParameter('id', $aw['data']['id'])->execute();

        // Do a transfer
        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $aw['data']['id'], 'toWalletId' => $bw['data']['id'], 'amount' => 10000,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // List transactions
        $client->request('GET', '/api/v1/manage/transactions');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $list['data']);
        self::assertSame('completed', $list['data'][0]['status']);
    }

    // ------------------------------------------------
    //  Fuzz / Blind Testing
    // ------------------------------------------------

    public static function blindTransferProvider(): array
    {
        return [
            'missing fromWalletId' => [['toWalletId' => 1, 'amount' => 100], 400],
            'missing toWalletId' => [['fromWalletId' => 1, 'amount' => 100], 400],
            'missing amount' => [['fromWalletId' => 1, 'toWalletId' => 2], 400],
            'string amount' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 'abc'], 400],
            'negative amount' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => -100], 400],
            'zero amount' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 0], 400],
            'float amount' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 50.5], 201], // casts to int 50, passes
            'wrong types' => [['fromWalletId' => 'x', 'toWalletId' => 'y', 'amount' => 'z'], 400],
            // Empty array body is a no-op under the mixin lifecycle (no items to process).
            'empty body' => [[], 201],
            'extra fields' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100, 'unknown' => true], 201], // extra fields should not break valid payload
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blindTransferProvider')]
    public function testBlindTransferInputs(array $payload, int $expectedStatus): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        // Each dataset needs unique usernames; use a random suffix
        $suffix = bin2hex(random_bytes(4));
        $alice = $this->createTestUser($em, "blind_a_{$suffix}");
        $bob = $this->createTestUser($em, "blind_b_{$suffix}");

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['user' => $alice->getId(), 'currency' => 'USD'], JSON_THROW_ON_ERROR));
        $aw = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['user' => $bob->getId(), 'currency' => 'USD'], JSON_THROW_ON_ERROR));
        $bw = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Replace IDs in fuzz payloads that expect success
        if (isset($payload['fromWalletId']) && is_int($payload['fromWalletId'])) {
            $payload['fromWalletId'] = $aw['data']['id'];
        }
        if (isset($payload['toWalletId']) && is_int($payload['toWalletId'])) {
            $payload['toWalletId'] = $bw['data']['id'];
        }

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 100000)->setParameter('id', $aw['data']['id'])->execute();

        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), 'Payload: ' . json_encode($payload));
    }

    // ------------------------------------------------
    //  Authentication check
    // ------------------------------------------------

    public function testTransferRequiresAuth(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => 100,
        ], JSON_THROW_ON_ERROR));
        self::assertNotSame(201, $client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------
    //  Edge cases: TransferController catch blocks
    // ------------------------------------------------

    public function testTransferNonexistentSourceWallet(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $bob = $this->createTestUser($em, 'nosource_bob');
        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['user' => $bob->getId(), 'currency' => 'USD'], JSON_THROW_ON_ERROR));
        $bw = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => 999999, 'toWalletId' => $bw['data']['id'], 'amount' => 100,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testTransferCurrencyMismatch(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createTestUser($em, 'mismatch_al');
        $bob = $this->createTestUser($em, 'mismatch_bo');

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['user' => $alice->getId(), 'currency' => 'USD'], JSON_THROW_ON_ERROR));
        $aw = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/v1/manage/wallets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['user' => $bob->getId(), 'currency' => 'EUR'], JSON_THROW_ON_ERROR));
        $bw = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :b WHERE w.id = :id')
            ->setParameter('b', 10000)->setParameter('id', $aw['data']['id'])->execute();

        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fromWalletId' => $aw['data']['id'], 'toWalletId' => $bw['data']['id'], 'amount' => 100,
        ], JSON_THROW_ON_ERROR));
        self::assertSame(500, $client->getResponse()->getStatusCode());
    }

    public function testTransferInvalidJson(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('POST', '/api/v1/manage/transactions', server: ['CONTENT_TYPE' => 'application/json'], content: '{invalid');
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }
}
