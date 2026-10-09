<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserApiIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private static ?int $testProductId = null;
    private static ?int $testSpecId = null;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $ref = new \ReflectionProperty(KernelTestCase::class, 'booted');
        $ref->setValue(null, false);
    }

    private function createSpecData(KernelBrowser $client): void
    {
        if (self::$testProductId !== null) {
            return;
        }

        $adminToken = $this->createAdminAndGetToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/products', [
            'name' => 'Test Product for Specs',
            'description' => 'Integration test product',
            'status' => 'active',
        ]);
        $data = $this->decodeJson($client);
        self::$testProductId = $data['data']['id'] ?? null;
        self::assertNotNull(self::$testProductId, 'Failed to create test product');

        $client->jsonRequest('POST', '/api/v1/manage/products/' . self::$testProductId . '/specifications', [
            'name' => 'Test Specification',
            'price' => 500,
            'status' => 'active',
            'sort' => 1,
        ]);
        $data = $this->decodeJson($client);
        self::$testSpecId = $data['data']['id'] ?? null;
    }

    // ───────────────────── Register via AuthController ─────────────────────

    public function testRegisterCreatesUserAndReturnsTokens(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'regtest@example.com',
            'username' => 'regtest',
            'password' => 'P@ssw0rd',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->decodeJson($client);
        self::assertArrayHasKey('access_token', $data);
        self::assertArrayHasKey('refresh_token', $data);
        self::assertSame(7200, $data['expires_in']);
    }

    public function testRegisterWithPhone(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'phoneuser@example.com',
            'username' => 'phoneuser',
            'password' => 'P@ssw0rd',
            'phone' => '+8613999999999',
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testRegisterDuplicateEmail(): void
    {
        $this->createTestUser('dup@example.com', 'dup1');

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'dup@example.com',
            'username' => 'dup2',
            'password' => 'P@ssw0rd',
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = $this->decodeJson($client);
        self::assertStringContainsString('Email already exists', (string) ($data['message'] ?? ''));
    }

    public function testRegisterDuplicateUsername(): void
    {
        $this->createTestUser('a@test.com', 'unique_name');

        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'b@test.com',
            'username' => 'unique_name',
            'password' => 'P@ssw0rd',
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = $this->decodeJson($client);
        self::assertStringContainsString('Username already exists', (string) ($data['message'] ?? ''));
    }

    #[Group('low-value')]
    public function testRegisterMissingFields(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'x@x.com',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Group('low-value')]
    public function testRegisterShortPassword(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/auth/register', [
            'email' => 'short@test.com',
            'username' => 'shortpw',
            'password' => 'ab',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    // ───────────────────── App User Profile ─────────────────────

    public function testGetProfile(): void
    {
        $this->createTestUser('profile@test.com', 'profileuser');
        $token = $this->loginAndGetToken('profile@test.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->request('GET', '/api/v1/app/users/me');

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        $userData = $data['data'] ?? [];
        self::assertSame('profile@test.com', $userData['email']);
        self::assertSame('profileuser', $userData['username']);
    }

    public function testGetProfileUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/app/users/me');

        self::assertTrue(
            in_array($client->getResponse()->getStatusCode(), [401, 500], true),
            'Expected 401 or 500 for unauthenticated request'
        );
    }

    // ───────────────────── App Change Password ─────────────────────

    public function testChangePassword(): void
    {
        $this->createTestUser('changepw@test.com', 'changepw');
        $token = $this->loginAndGetToken('changepw@test.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->jsonRequest('POST', '/api/v1/app/users/change-password', [
            'currentPassword' => 'P@ssw0rd',
            'newPassword' => 'NewPass99!',
        ]);

        self::assertResponseStatusCodeSame(200);

        // Verify can login with new password
        self::ensureKernelShutdown();
        $client2 = static::createClient();
        $client2->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'changepw@test.com',
            'password' => 'NewPass99!',
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testChangePasswordWrongCurrent(): void
    {
        $this->createTestUser('wrongpw@test.com', 'wrongpw');
        $token = $this->loginAndGetToken('wrongpw@test.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->jsonRequest('POST', '/api/v1/app/users/change-password', [
            'currentPassword' => 'WrongPassword',
            'newPassword' => 'NewPass99!',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    // ───────────────────── App Update Profile ─────────────────────

    public function testUpdateProfile(): void
    {
        $this->createTestUser('updateprof@test.com', 'updprof');
        $token = $this->loginAndGetToken('updateprof@test.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->jsonRequest('PUT', '/api/v1/app/users/me', [
            'username' => 'renamed_user',
            'phone' => '+8613888888888',
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        $userData = $data['data'] ?? [];
        self::assertSame('renamed_user', $userData['username']);
        self::assertSame('+8613888888888', $userData['phone']);
    }

    public function testUpdateProfileDuplicateEmail(): void
    {
        $this->createTestUser('first@test.com', 'firstuser');
        $this->createTestUser('second@test.com', 'seconduser');
        $token = $this->loginAndGetToken('second@test.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->jsonRequest('PUT', '/api/v1/app/users/me', [
            'email' => 'first@test.com',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    // ───────────────────── Manage User CRUD ─────────────────────

    public function testManageListUsers(): void
    {
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/users?limit=50');

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        self::assertArrayHasKey('data', $data);
        self::assertGreaterThan(0, count($data['data']));
    }

    public function testManageCreateUser(): void
    {
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/users', [
            'email' => 'mgmt@example.com',
            'username' => 'mgmtuser',
            'password' => 'MgmtPass1!',
            'roles' => ['ROLE_EDITOR'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->decodeJson($client);
        $userData = $data['data'] ?? [];
        self::assertSame('mgmt@example.com', $userData['email']);
        self::assertContains('ROLE_EDITOR', $userData['roles']);
    }

    public function testManageViewUser(): void
    {
        $this->createTestUser('viewuser@test.com', 'viewuser');
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/users?limit=50');
        $listData = $this->decodeJson($client);
        $userId = null;
        foreach ($listData['data'] as $u) {
            if (($u['email'] ?? '') === 'viewuser@test.com') {
                $userId = $u['id'];
                break;
            }
        }
        self::assertNotNull($userId, 'Could not find viewuser in manage list');

        $client->request('GET', '/api/v1/manage/users/' . $userId);
        self::assertResponseStatusCodeSame(200);
    }

    public function testManageUpdateUser(): void
    {
        $this->createTestUser('updateuser@test.com', 'updateuser');
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/users?limit=50');
        $listData = $this->decodeJson($client);
        $userId = null;
        foreach ($listData['data'] as $u) {
            if (($u['email'] ?? '') === 'updateuser@test.com') {
                $userId = $u['id'];
                break;
            }
        }
        self::assertNotNull($userId);

        $client->jsonRequest('PUT', '/api/v1/manage/users/' . $userId, [
            'username' => 'renamed',
            'phone' => '+8613777777777',
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testManageDeleteUser(): void
    {
        $this->createTestUser('deleteuser@test.com', 'deleteuser');
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/users?limit=50');
        $listData = $this->decodeJson($client);
        $userId = null;
        foreach ($listData['data'] as $u) {
            if (($u['email'] ?? '') === 'deleteuser@test.com') {
                $userId = $u['id'];
                break;
            }
        }
        self::assertNotNull($userId);

        $client->request('DELETE', '/api/v1/manage/users/' . $userId);
        self::assertResponseStatusCodeSame(204);
    }

    public function testManageChangeUserPassword(): void
    {
        $this->createTestUser('adminpw@test.com', 'adminpw');
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/users?limit=50');
        $listData = $this->decodeJson($client);
        $userId = null;
        foreach ($listData['data'] as $u) {
            if (($u['email'] ?? '') === 'adminpw@test.com') {
                $userId = $u['id'];
                break;
            }
        }
        self::assertNotNull($userId);

        $client->jsonRequest('POST', '/api/v1/manage/users/' . $userId . '/change-password', [
            'newPassword' => 'AdminSet1!',
        ]);
        self::assertResponseStatusCodeSame(200);

        // Verify new password works
        self::ensureKernelShutdown();
        $client2 = static::createClient();
        $client2->jsonRequest('POST', '/api/auth/login', [
            'identifier' => 'adminpw@test.com',
            'password' => 'AdminSet1!',
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testManageUsersRequiresAdmin(): void
    {
        $this->createTestUser('normie@test.com', 'normie');
        $token = $this->loginAndGetToken('normie@test.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->request('GET', '/api/v1/manage/users');

        self::assertTrue(
            in_array($client->getResponse()->getStatusCode(), [403, 500], true),
            'Expected 403 or 500 for non-admin manage request'
        );
    }

    // ───────────────────── App Specifications ─────────────────────

    #[Group('low-value')]
    public function testAppListSpecifications(): void
    {
        $client = static::createClient();
        $this->createSpecData($client);

        $token = $this->createAnyToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->request('GET', '/api/v1/app/specifications');

        self::assertResponseStatusCodeSame(200);
    }

    #[Group('low-value')]
    public function testAppSpecificationsByProduct(): void
    {
        $client = static::createClient();
        $this->createSpecData($client);

        $token = $this->createAnyToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->request('GET', '/api/v1/app/specifications/by-product/' . self::$testProductId);

        self::assertResponseStatusCodeSame(200);
    }

    #[Group('low-value')]
    public function testAppSpecificationDetail(): void
    {
        $client = static::createClient();
        $this->createSpecData($client);

        $token = $this->createAnyToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->request('GET', '/api/v1/app/specifications/' . self::$testSpecId);

        self::assertResponseStatusCodeSame(200);
    }

    // ───────────────────── Manage Specification Detail ─────────────────────

    #[Group('low-value')]
    public function testManageSpecificationDetail(): void
    {
        $client = static::createClient();
        $this->createSpecData($client);

        $adminToken = $this->createAdminAndGetToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/products/' . self::$testProductId . '/specifications/' . self::$testSpecId);

        self::assertResponseStatusCodeSame(200);
    }

    // ───────────────────── Wallet Deposit + Transfer ─────────────────────

    #[Group('low-value')]
    public function testBalanceVerification(): void
    {
        $client = static::createClient();
        $adminToken = $this->createAdminAndGetToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/wallets/balance');

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        $result = $data['data'] ?? [];

        // Core invariant: balance must equal voucher boundary + unmatched legacy deposits per unit
        self::assertTrue(
            $result['matches'] ?? false,
            'Wallet balance must equal the voucher boundary plus unmatched legacy deposits'
        );
        foreach ($result['units'] ?? [] as $unit) {
            self::assertTrue($unit['matches'], 'Unit ' . $unit['currency'] . ' must balance');
            self::assertSame(
                $unit['voucherBoundary'] + $unit['unmatchedDeposits'],
                $unit['totalBalance'],
                'Unit ' . $unit['currency'] . ' balance must equal expected'
            );
        }
        self::assertGreaterThanOrEqual(0, $result['walletCount'] ?? -1);
    }

    #[Group('low-value')]
    public function testReconcileAfterDepositProducesZero(): void
    {
        $client = static::createClient();
        $adminToken = $this->createAdminAndGetToken($client);
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        // Create user + wallet + deposit
        $user = new User();
        $user->setEmail('recuser@t.com')->setUsername('recuser');
        $user->setPassword($hasher->hashPassword($user, 'P@ssw0rd'));
        $em->persist($user);
        $em->flush();

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/wallets', [
            'user' => $user->getId(), 'currency' => 'CNY', 'status' => 'active',
        ]);
        $wid = $this->decodeJson($client)['data']['id'];

        $this->seedWalletBalance($em, $wid, 10000);

        // Reconcile should produce 0 — books are balanced
        $client->jsonRequest('POST', '/api/v1/manage/wallets/reconcile');
        self::assertResponseStatusCodeSame(200);
        $r = $this->decodeJson($client)['data'];
        self::assertSame(0, $r['reconciled']);
    }

    // ───────────────── UserController edge cases ─────────────────

    #[Group('low-value')]
    public function testChangePasswordMissingFields(): void
    {
        $this->createTestUser('mfield@t.com', 'mfield');
        $token = $this->loginAndGetToken('mfield@t.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->jsonRequest('POST', '/api/v1/app/users/change-password', [
            'currentPassword' => '',
            'newPassword' => '',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordShortNew(): void
    {
        $this->createTestUser('shortp@t.com', 'shortp');
        $token = $this->loginAndGetToken('shortp@t.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->jsonRequest('POST', '/api/v1/app/users/change-password', [
            'currentPassword' => 'P@ssw0rd',
            'newPassword' => 'ab',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[Group('low-value')]
    public function testManageChangePasswordShort(): void
    {
        $this->createTestUser('adms@t.com', 'adms');
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/users?limit=50');
        $uid = null;
        foreach ($this->decodeJson($client)['data'] as $u) {
            if (($u['email'] ?? '') === 'adms@t.com') { $uid = $u['id']; break; }
        }
        self::assertNotNull($uid);

        $client->jsonRequest('POST', '/api/v1/manage/users/' . $uid . '/change-password', [
            'newPassword' => 'ab',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    #[Group('low-value')]
    public function testUpdateProfileShortPasswordRejected(): void
    {
        $this->createTestUser('shortr@t.com', 'shortr');
        $token = $this->loginAndGetToken('shortr@t.com');

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->jsonRequest('POST', '/api/v1/app/users/change-password', [
            'currentPassword' => 'P@ssw0rd',
            'newPassword' => 'ab',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    // ───────────────────── Transfer endpoint ─────────────────────

    public function testTransferBetweenWallets(): void
    {
        $client = static::createClient();
        $adminToken = $this->createAdminAndGetToken($client);
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $userA = new User();
        $userA->setEmail('trua@t.com')->setUsername('trua');
        $userA->setPassword($hasher->hashPassword($userA, 'P@ssw0rd'));
        $em->persist($userA);
        $userB = new User();
        $userB->setEmail('trub@t.com')->setUsername('trub');
        $userB->setPassword($hasher->hashPassword($userB, 'P@ssw0rd'));
        $em->persist($userB);
        $em->flush();

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/wallets', [
            'user' => $userA->getId(), 'currency' => 'CNY', 'status' => 'active', 'label' => 'A',
        ]);
        $wa = $this->decodeJson($client)['data']['id'];
        $client->jsonRequest('POST', '/api/v1/manage/wallets', [
            'user' => $userB->getId(), 'currency' => 'CNY', 'status' => 'active', 'label' => 'B',
        ]);
        $wb = $this->decodeJson($client)['data']['id'];

        // Seed wallet A with funds (deposit is now voucher-backed)
        $this->seedWalletBalance($em, $wa, 50000);

        // Transfer from A to B
        $client->jsonRequest('POST', '/api/v1/manage/transactions', [
            'fromWalletId' => $wa, 'toWalletId' => $wb, 'amount' => 10000, 'description' => 'test transfer',
        ]);
        self::assertResponseStatusCodeSame(201);
        $t = $this->decodeJson($client)['data'];
        self::assertSame(40000, $t['fromWalletBalanceAfter']);
        self::assertSame(10000, $t['toWalletBalanceAfter']);

        // Insufficient funds (mixin lifecycle maps client errors to 400)
        $client->jsonRequest('POST', '/api/v1/manage/transactions', [
            'fromWalletId' => $wa, 'toWalletId' => $wb, 'amount' => 999999, 'description' => 'too much',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    #[Group('low-value')]
    public function testTransferSameWalletRejected(): void
    {
        $client = static::createClient();
        $adminToken = $this->createAdminAndGetToken($client);
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('samew@t.com')->setUsername('samew');
        $user->setPassword($hasher->hashPassword($user, 'P@ssw0rd'));
        $em->persist($user);
        $em->flush();

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/wallets', [
            'user' => $user->getId(), 'currency' => 'CNY', 'status' => 'active',
        ]);
        $wid = $this->decodeJson($client)['data']['id'];

        $this->seedWalletBalance($em, $wid, 50000);

        // Same wallet transfer — should be rejected
        $client->jsonRequest('POST', '/api/v1/manage/transactions', [
            'fromWalletId' => $wid, 'toWalletId' => $wid, 'amount' => 100,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    #[Group('low-value')]
    public function testTransferNegativeAmountRejected(): void
    {
        $client = static::createClient();
        $adminToken = $this->createAdminAndGetToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/transactions', [
            'fromWalletId' => 1, 'toWalletId' => 2, 'amount' => -100,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    #[Group('low-value')]
    public function testTransferMissingFields(): void
    {
        $client = static::createClient();
        $adminToken = $this->createAdminAndGetToken($client);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/transactions', [
            'fromWalletId' => 1,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testManageViewUserNotFound(): void
    {
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/users/99999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testManageChangePasswordUserNotFound(): void
    {
        $adminToken = $this->createAdminAndGetToken();

        $client = static::createClient();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/users/99999/change-password', [
            'newPassword' => 'Whatever1!',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ───────────────────── Helpers ─────────────────────

    private function createTestUser(string $email, string $username): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($hasher->hashPassword($user, 'P@ssw0rd'));
        $em->persist($user);
        $em->flush();
        self::ensureKernelShutdown();
    }

    private function loginAndGetToken(string $identifier, string $password = 'P@ssw0rd', ?KernelBrowser $client = null): string
    {
        $owned = $client === null;
        $client ??= static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => $identifier,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        if ($owned) {
            self::ensureKernelShutdown();
        }
        return $data['access_token'];
    }

    private function createAdminAndGetToken(?KernelBrowser $client = null): string
    {
        $owned = $client === null;
        $client ??= static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'testadmin@example.com']);
        if ($admin === null) {
            $admin = new User();
            $admin->setEmail('testadmin@example.com');
            $admin->setUsername('testadmin');
            $admin->setPassword($hasher->hashPassword($admin, 'AdminPass!'));
            $admin->setRoles(['ROLE_ADMIN']);
            $em->persist($admin);
            $em->flush();
        }
        if ($owned) {
            self::ensureKernelShutdown();
        }
        return $this->loginAndGetToken('testadmin@example.com', 'AdminPass!', $owned ? null : $client);
    }

    private function seedWalletBalance(EntityManagerInterface $em, int $walletId, int $amount): void
    {
        $em->createQuery('UPDATE App\Wallet\Entity\Wallet w SET w.balance = :balance WHERE w.id = :id')
            ->setParameter('balance', $amount)
            ->setParameter('id', $walletId)
            ->execute();

        $wallet = $em->getRepository(\App\Wallet\Entity\Wallet::class)->find($walletId);
        self::assertNotNull($wallet);
        $tx = new \App\Wallet\Entity\Transaction(
            'identity-seed-' . bin2hex(random_bytes(6)),
            $amount,
            \App\Wallet\Entity\Transaction::TYPE_DEPOSIT,
        );
        $tx->setToWallet($wallet)->markCompleted();
        $em->persist($tx);
        $em->flush();
    }

    private function createAnyToken(?KernelBrowser $client = null): string
    {
        $owned = $client === null;
        $client ??= static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'anytest@example.com']);
        if ($user === null) {
            $user = new User();
            $user->setEmail('anytest@example.com');
            $user->setUsername('anytest');
            $user->setPassword($hasher->hashPassword($user, 'Test123!'));
            $em->persist($user);
            $em->flush();
        }
        if ($owned) {
            self::ensureKernelShutdown();
        }
        return $this->loginAndGetToken('anytest@example.com', 'Test123!', $owned ? null : $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson($client): array
    {
        $content = (string) $client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
