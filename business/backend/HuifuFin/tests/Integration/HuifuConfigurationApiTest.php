<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Integration;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\HuifuFin\Command\SeedHuifuAuthorizationCommand;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuSecretCipher;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class HuifuConfigurationApiTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if ($entityManager->getConnection()->createSchemaManager()->listTableNames() !== []) {
            $schemaTool->dropSchema($metadata);
        }
        $schemaTool->createSchema($metadata);
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAdminProfilesCanBeSharedOrIsolatedAcrossStores(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('huifu-admin@example.test')->setUsername('huifu-admin')->setRoles(['ROLE_ADMIN']);
        $owner = (new User())->setEmail('huifu-owner@example.test')->setUsername('huifu-owner');
        $storeA = new Store('huifu-store-a', 'Huifu Store A');
        $storeB = new Store('huifu-store-b', 'Huifu Store B');
        $ungrantedStore = new Store('huifu-store-c', 'Huifu Store C');
        foreach ([$admin, $owner, $storeA, $storeB, $ungrantedStore] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach ([$storeA, $storeB, $ungrantedStore] as $store) {
            $em->persist(new Membership($store, $owner->getUuid(), Membership::ROLE_OWNER));
        }
        $seed = new CommandTester(new SeedHuifuAuthorizationCommand($em));
        self::assertSame(0, $seed->execute([]));
        $role = $em->getRepository(Role::class)->findOneBy(['code' => 'store_huifu_manager']);
        self::assertInstanceOf(Role::class, $role);
        foreach ([$storeA, $storeB, $ungrantedStore] as $store) {
            $em->persist(new Assignment($role, $owner->getUuid(), Assignment::SCOPE_STORE, $store->getUuid()));
        }
        $em->flush();

        $adminToken = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
        $ownerToken = self::getContainer()->get(TokenManager::class)->createAccessToken($owner);
        [$privateKey, $publicKey] = $this->keyPair();

        $profile = $this->request('POST', '/api/v1/manage/huifu-provider-profiles', $adminToken, [
            'name' => 'Huifu production',
            'environment' => 'production',
            'sysId' => '6666000000000001',
            'productId' => 'YMFZS',
            'merchantPrivateKey' => $privateKey,
            'huifuPublicKey' => $publicKey,
            'asyncPublicKey' => $publicKey,
        ]);
        self::assertSame(201, $profile['status'], json_encode($profile, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('credentialsCipher', $profile['data']);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', json_encode($profile, JSON_THROW_ON_ERROR));
        $profileUuid = $profile['data']['uuid'];
        $storedProfile = $em->getRepository(HuifuProviderProfile::class)->findOneBy(['uuid' => $profileUuid]);
        self::assertInstanceOf(HuifuProviderProfile::class, $storedProfile);
        self::assertSame(trim($publicKey), trim($storedProfile->decryptCredentials(self::getContainer()->get(HuifuSecretCipher::class))['huifu_public_key']));

        [$rotatedPrivateKey, $rotatedPublicKey] = $this->keyPair();
        $rotated = $this->request('PUT', '/api/v1/manage/huifu-provider-profiles/'.$profile['data']['id'], $adminToken, [
            'merchantPrivateKey' => $rotatedPrivateKey,
            'huifuPublicKey' => $rotatedPublicKey,
        ]);
        self::assertSame(200, $rotated['status'], json_encode($rotated, JSON_THROW_ON_ERROR));
        $storedProfile = $em->getRepository(HuifuProviderProfile::class)->findOneBy(['uuid' => $profileUuid]);
        self::assertInstanceOf(HuifuProviderProfile::class, $storedProfile);
        self::assertSame(2, $storedProfile->getCredentialVersion());
        $cipher = self::getContainer()->get(HuifuSecretCipher::class);
        self::assertSame(trim($publicKey), trim($storedProfile->decryptCredentialsForVersion(1, $cipher)['huifu_public_key']));
        self::assertSame(trim($rotatedPublicKey), trim($storedProfile->decryptCredentialsForVersion(2, $cipher)['huifu_public_key']));

        $merchantA = $this->request('POST', '/api/v1/manage/huifu-merchants', $adminToken, [
            'name' => 'Merchant A', 'huifuId' => '6666000000000101', 'providerUuid' => $profileUuid,
            'capabilities' => ['jspay', 'native'],
        ]);
        self::assertSame(201, $merchantA['status'], json_encode($merchantA, JSON_THROW_ON_ERROR));
        $merchantB = $this->request('POST', '/api/v1/manage/huifu-merchants', $adminToken, [
            'name' => 'Merchant B', 'huifuId' => '6666000000000102', 'providerUuid' => $profileUuid,
            'capabilities' => ['jspay'],
        ]);
        self::assertSame(201, $merchantB['status'], json_encode($merchantB, JSON_THROW_ON_ERROR));

        foreach ([$storeA, $storeB] as $store) {
            $grant = $this->request('POST', '/api/v1/manage/huifu-merchant-store-grants', $adminToken, [
                'merchantUuid' => $merchantA['data']['uuid'], 'storeUuid' => $store->getUuid(),
            ]);
            self::assertSame(201, $grant['status'], json_encode($grant, JSON_THROW_ON_ERROR));
        }
        $this->request('POST', '/api/v1/manage/huifu-merchant-store-grants', $adminToken, [
            'merchantUuid' => $merchantB['data']['uuid'], 'storeUuid' => $storeA->getUuid(),
        ]);

        $settingA = $this->request('POST', '/api/v1/store/'.$storeA->getUuid().'/huifu-settings', $ownerToken, [
            'merchantUuid' => $merchantA['data']['uuid'], 'allowedMethods' => ['jspay', 'native'], 'defaultMethod' => 'jspay',
        ]);
        self::assertSame(201, $settingA['status'], json_encode($settingA, JSON_THROW_ON_ERROR));
        self::assertSame($storeA->getUuid(), $settingA['data']['storeUuid']);
        self::assertSame($merchantA['data']['uuid'], $settingA['data']['merchantUuid']);

        $settingB = $this->request('POST', '/api/v1/store/'.$storeB->getUuid().'/huifu-settings', $ownerToken, [
            'merchantUuid' => $merchantA['data']['uuid'],
        ]);
        self::assertSame(201, $settingB['status'], json_encode($settingB, JSON_THROW_ON_ERROR));

        $optionsA = $this->request('GET', '/api/v1/store/'.$storeA->getUuid().'/huifu-settings/options', $ownerToken);
        self::assertCount(2, $optionsA['merchants']);
        $optionsB = $this->request('GET', '/api/v1/store/'.$storeB->getUuid().'/huifu-settings/options', $ownerToken);
        self::assertCount(1, $optionsB['merchants']);

        $rejected = $this->request('POST', '/api/v1/store/'.$ungrantedStore->getUuid().'/huifu-settings', $ownerToken, [
            'merchantUuid' => $merchantA['data']['uuid'],
        ]);
        self::assertSame(400, $rejected['status']);
    }

    /** @return array{0: string, 1: string} */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        self::assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        return [$privateKey, $details['key']];
    }

    /** @param array<string, mixed>|null $body
     *  @return array<string, mixed> */
    private function request(string $method, string $path, string $token, ?array $body = null): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
        ];
        $response = self::$kernel->handle(Request::create(
            $path,
            $method,
            [],
            [],
            [],
            $server,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        ));
        $decoded = json_decode($response->getContent(), true);

        return is_array($decoded) ? $decoded + ['status' => $response->getStatusCode()] : ['status' => $response->getStatusCode()];
    }
}
