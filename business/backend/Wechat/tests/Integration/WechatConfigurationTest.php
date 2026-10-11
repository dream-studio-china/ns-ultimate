<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Core\Utils\UUID;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\StoreWechatSettingRevision;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Exception\WechatConfigurationException;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;
use NsUltimate\Business\Wechat\Migrations\Version20261011040000;
use NsUltimate\Business\Wechat\Service\SecretCipher;
use NsUltimate\Business\Wechat\Service\StoreWechatBindingService;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use NsUltimate\Business\Wechat\Service\WechatApplicationService;
use NsUltimate\Business\Wechat\Service\WechatClientFactory;
use NsUltimate\Business\Wechat\Service\WechatMerchantService;
use NsUltimate\Integration\Backend\Kernel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WechatConfigurationTest extends KernelTestCase
{
    private const PRIVATE_KEY_FIXTURE = "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n";
    private const CERTIFICATE_FIXTURE = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n";

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

    public function testWechatEntitiesAreRegistered(): void
    {
        $entityNames = array_map(
            static fn ($item): string => $item->getName(),
            self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata(),
        );

        self::assertContains(WechatApplication::class, $entityNames);
        self::assertContains(WechatApplicationRevision::class, $entityNames);
        self::assertContains(WechatMerchant::class, $entityNames);
        self::assertContains(WechatMerchantRevision::class, $entityNames);
        self::assertContains(StoreWechatSetting::class, $entityNames);
        self::assertContains(StoreWechatSettingRevision::class, $entityNames);
    }

    public function testSecretCipherRoundTripsAndDetectsTampering(): void
    {
        $cipher = self::getContainer()->get(SecretCipher::class);

        $payload = $cipher->encrypt('top-secret-value');
        self::assertStringNotContainsString('top-secret-value', $payload);
        self::assertSame('top-secret-value', $cipher->decrypt($payload));

        $tampered = substr($payload, 0, -2).'AA';
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tampered);
    }

    public function testPublishedConfigurationResolvesPerStoreAndEncryptsSecrets(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $applicationService = self::getContainer()->get(WechatApplicationService::class);
        $merchantService = self::getContainer()->get(WechatMerchantService::class);
        $bindingService = self::getContainer()->get(StoreWechatBindingService::class);
        $resolver = self::getContainer()->get(StoreWechatResolver::class);

        $storeUuid = UUID::v4();
        $applicationRevision = $this->publishApplication($applicationService, 'wx-mini-a', 'secret-a');
        $merchantRevision = $this->publishMerchant($merchantService, '1900000001');

        $setting = $bindingService->settingForStore($storeUuid);
        $binding = $bindingService->createRevision($setting, [
            'miniappRevisionUuid' => $applicationRevision->getUuid(),
            'merchantRevisionUuid' => $merchantRevision->getUuid(),
            'capabilities' => [
                StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true,
                StoreWechatSettingRevision::CAP_PAYMENT_JSAPI => true,
            ],
        ]);
        $bindingService->publish($binding);

        $config = $resolver->resolve($storeUuid, StoreWechatSettingRevision::CAP_MINIAPP_LOGIN);
        self::assertSame('wx-mini-a', $config->appId);
        self::assertSame('secret-a', $config->appSecret);
        self::assertTrue($resolver->isConfigured($storeUuid, StoreWechatSettingRevision::CAP_MINIAPP_LOGIN));

        $payment = $resolver->resolve($storeUuid, StoreWechatSettingRevision::CAP_PAYMENT_JSAPI);
        self::assertSame('1900000001', $payment->merchantId);
        self::assertSame(WechatMerchant::MODE_DIRECT, $payment->merchantMode);
        self::assertSame(str_repeat('k', 32), $payment->apiV3Key);

        $stored = $entityManager->getConnection()->fetchOne(
            'SELECT secret_encrypted FROM wechat_application_revision WHERE uuid = ?',
            [$applicationRevision->getUuid()],
        );
        self::assertIsString($stored);
        self::assertStringNotContainsString('secret-a', $stored);
    }

    public function testStoresResolveIndependently(): void
    {
        $applicationService = self::getContainer()->get(WechatApplicationService::class);
        $bindingService = self::getContainer()->get(StoreWechatBindingService::class);
        $resolver = self::getContainer()->get(StoreWechatResolver::class);

        $storeA = UUID::v4();
        $storeB = UUID::v4();
        $applicationA = $this->publishApplication($applicationService, 'wx-mini-a', 'secret-a');
        $applicationB = $this->publishApplication($applicationService, 'wx-mini-b', 'secret-b');

        $this->bindCapability($bindingService, $storeA, $applicationA, [StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true]);
        $this->bindCapability($bindingService, $storeB, $applicationB, [StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true]);

        self::assertSame('wx-mini-a', $resolver->resolve($storeA, StoreWechatSettingRevision::CAP_MINIAPP_LOGIN)->appId);
        self::assertSame('wx-mini-b', $resolver->resolve($storeB, StoreWechatSettingRevision::CAP_MINIAPP_LOGIN)->appId);
    }

    public function testUnconfiguredStoreFailsClosed(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);

        $this->expectException(WechatConfigurationException::class);
        $resolver->resolve(UUID::v4(), StoreWechatSettingRevision::CAP_MINIAPP_LOGIN);
    }

    public function testDisabledCapabilityFailsClosed(): void
    {
        $applicationService = self::getContainer()->get(WechatApplicationService::class);
        $bindingService = self::getContainer()->get(StoreWechatBindingService::class);
        $resolver = self::getContainer()->get(StoreWechatResolver::class);

        $storeUuid = UUID::v4();
        $application = $this->publishApplication($applicationService, 'wx-mini-a', 'secret-a');
        $this->bindCapability($bindingService, $storeUuid, $application, [StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true]);

        $this->expectException(WechatConfigurationException::class);
        $resolver->resolve($storeUuid, StoreWechatSettingRevision::CAP_OFFICIAL_OAUTH);
    }

    public function testBindingRejectsDraftApplicationRevision(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $applicationService = self::getContainer()->get(WechatApplicationService::class);
        $bindingService = self::getContainer()->get(StoreWechatBindingService::class);

        $application = new WechatApplication('Draft mini', WechatApplication::TYPE_MINIAPP, 'wx-draft');
        $entityManager->persist($application);
        $entityManager->flush();
        $draft = $applicationService->createRevision($application, ['secret' => 'draft-secret']);

        $setting = $bindingService->settingForStore(UUID::v4());
        $this->expectException(WechatValidationException::class);
        $bindingService->createRevision($setting, [
            'miniappRevisionUuid' => $draft->getUuid(),
            'capabilities' => [StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true],
        ]);
    }

    public function testTamperedCredentialsFailResolution(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $applicationService = self::getContainer()->get(WechatApplicationService::class);
        $bindingService = self::getContainer()->get(StoreWechatBindingService::class);
        $resolver = self::getContainer()->get(StoreWechatResolver::class);

        $storeUuid = UUID::v4();
        $application = $this->publishApplication($applicationService, 'wx-mini-a', 'secret-a');
        $this->bindCapability($bindingService, $storeUuid, $application, [StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true]);

        $entityManager->getConnection()->executeStatement(
            'UPDATE wechat_application_revision SET secret_encrypted = ? WHERE uuid = ?',
            ['v1:deadbeef:AAAA:AAAA:AAAA', $application->getUuid()],
        );
        $entityManager->clear();

        $this->expectException(WechatConfigurationException::class);
        $resolver->resolve($storeUuid, StoreWechatSettingRevision::CAP_MINIAPP_LOGIN);
    }

    public function testClientFactoryUsesResolvedIdentityAndIsolatesCache(): void
    {
        $applicationService = self::getContainer()->get(WechatApplicationService::class);
        $bindingService = self::getContainer()->get(StoreWechatBindingService::class);
        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        $factory = self::getContainer()->get(WechatClientFactory::class);

        $storeUuid = UUID::v4();
        $application = $this->publishApplication($applicationService, 'wx-mini-a', 'secret-a');
        $this->bindCapability($bindingService, $storeUuid, $application, [StoreWechatSettingRevision::CAP_MINIAPP_LOGIN => true]);

        $config = $resolver->resolve($storeUuid, StoreWechatSettingRevision::CAP_MINIAPP_LOGIN);
        $miniApp = $factory->miniApp($config);

        self::assertSame('wx-mini-a', $miniApp->getAccount()->getAppId());
        self::assertSame('wechat:miniapp:wx-mini-a', $miniApp->getCacheNamespace());
        self::assertSame($miniApp, $factory->miniApp($config));
    }

    public function testMigrationCreatesExpectedTables(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261011040000.php';

        $databaseFile = sys_get_temp_dir().'/ns-ultimate-wechat-migration-'.bin2hex(random_bytes(4)).'.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databaseFile]);

        try {
            $migration = new Version20261011040000($connection, new NullLogger());
            $fromSchema = $connection->createSchemaManager()->introspectSchema();
            $toSchema = clone $fromSchema;
            $migration->up($toSchema);

            $diff = $connection->createSchemaManager()->createComparator()->compareSchemas($fromSchema, $toSchema);
            foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($diff) as $statement) {
                $connection->executeStatement($statement);
            }

            $schemaManager = $connection->createSchemaManager();
            foreach ([
                'wechat_application',
                'wechat_application_revision',
                'wechat_merchant',
                'wechat_merchant_revision',
                'store_wechat_setting',
                'store_wechat_setting_revision',
            ] as $table) {
                self::assertTrue($schemaManager->tablesExist([$table]), sprintf('Missing table %s', $table));
            }

            self::assertContains('uniq_wechat_application_app_id', array_keys($schemaManager->listTableIndexes('wechat_application')));
            self::assertContains('uniq_store_wechat_setting_store_uuid', array_keys($schemaManager->listTableIndexes('store_wechat_setting')));
            self::assertContains('idx_wechat_application_revision_state', array_keys($schemaManager->listTableIndexes('wechat_application_revision')));
            self::assertSame([], $schemaManager->listTableForeignKeys('wechat_application'));
        } finally {
            $connection->close();
            if (is_file($databaseFile)) {
                unlink($databaseFile);
            }
        }
    }

    private function publishApplication(
        WechatApplicationService $applicationService,
        string $appId,
        string $secret,
    ): WechatApplicationRevision {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $application = new WechatApplication('Mini '.$appId, WechatApplication::TYPE_MINIAPP, $appId);
        $entityManager->persist($application);
        $entityManager->flush();

        $revision = $applicationService->createRevision($application, ['secret' => $secret]);
        $applicationService->publish($revision);

        return $revision;
    }

    private function publishMerchant(WechatMerchantService $merchantService, string $merchantId): WechatMerchantRevision
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $merchant = new WechatMerchant('Merchant '.$merchantId, $merchantId);
        $entityManager->persist($merchant);
        $entityManager->flush();

        $revision = $merchantService->createRevision($merchant, [
            'apiV3Key' => str_repeat('k', 32),
            'privateKey' => self::PRIVATE_KEY_FIXTURE,
            'certificate' => self::CERTIFICATE_FIXTURE,
        ]);
        $merchantService->publish($revision);

        return $revision;
    }

    /**
     * @param array<string, bool> $capabilities
     */
    private function bindCapability(
        StoreWechatBindingService $bindingService,
        string $storeUuid,
        WechatApplicationRevision $application,
        array $capabilities,
    ): StoreWechatSettingRevision {
        $setting = $bindingService->settingForStore($storeUuid);
        $binding = $bindingService->createRevision($setting, [
            'miniappRevisionUuid' => $application->getUuid(),
            'capabilities' => $capabilities,
        ]);
        $bindingService->publish($binding);

        return $binding;
    }
}
