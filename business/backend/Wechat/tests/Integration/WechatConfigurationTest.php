<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Core\Utils\UUID;
use Doctrine\DBAL\DriverManager;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Exception\WechatConfigurationException;
use NsUltimate\Business\Wechat\Migrations\Version20261011040000;
use NsUltimate\Business\Wechat\Service\SecretCipher;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use NsUltimate\Business\Wechat\Service\WechatClientFactory;
use Psr\Log\NullLogger;

final class WechatConfigurationTest extends WechatApiTestCase
{
    public function testSecretCipherRoundTripsAndDetectsTampering(): void
    {
        $cipher = self::getContainer()->get(SecretCipher::class);

        $payload = $cipher->encrypt('top-secret-value');
        self::assertStringNotContainsString('top-secret-value', $payload);
        self::assertSame('top-secret-value', $cipher->decrypt($payload));

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(substr($payload, 0, -2).'AA');
    }

    public function testPublishedConfigurationResolvesPerStoreAndEncryptsSecrets(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        $store = $this->createStore('A');
        $applicationRevision = $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a');
        $merchantRevision = $this->publishMerchantRevision($this->createMerchant('1900000001'));

        $this->createStoreSetting($store->getUuid(), $applicationRevision, $merchantRevision, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
            StoreWechatSetting::CAP_PAYMENT_JSAPI => true,
        ]);

        $config = $resolver->resolve($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN);
        self::assertSame('wx-mini-a', $config->appId);
        self::assertSame('secret-a', $config->appSecret);
        self::assertTrue($resolver->isConfigured($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN));

        $payment = $resolver->resolve($store->getUuid(), StoreWechatSetting::CAP_PAYMENT_JSAPI);
        self::assertSame('1900000001', $payment->merchantId);
        self::assertSame(WechatMerchant::MODE_DIRECT, $payment->merchantMode);
        self::assertSame(str_repeat('k', 32), $payment->apiV3Key);

        $stored = $this->entityManager()->getConnection()->fetchOne(
            'SELECT secret_encrypted FROM wechat_application_revision WHERE uuid = ?',
            [$applicationRevision],
        );
        self::assertIsString($stored);
        self::assertStringNotContainsString('secret-a', $stored);
    }

    public function testStoresResolveIndependently(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        $storeA = $this->createStore('A');
        $storeB = $this->createStore('B');

        $this->createStoreSetting(
            $storeA->getUuid(),
            $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a'),
            null,
            [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        );
        $this->createStoreSetting(
            $storeB->getUuid(),
            $this->publishApplicationRevision($this->createApplication('wx-mini-b'), 'secret-b'),
            null,
            [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        );

        self::assertSame('wx-mini-a', $resolver->resolve($storeA->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN)->appId);
        self::assertSame('wx-mini-b', $resolver->resolve($storeB->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN)->appId);
    }

    public function testPublishingNewRevisionDoesNotBreakExistingStoreBinding(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        $applicationId = $this->createApplication('wx-revision-stability');
        $firstRevision = $this->publishApplicationRevision($applicationId, 'first-secret');
        $store = $this->createStore('REV');
        $this->createStoreSetting($store->getUuid(), $firstRevision, null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);

        $this->publishApplicationRevision($applicationId, 'second-secret');

        self::assertSame('first-secret', $resolver->resolve(
            $store->getUuid(),
            StoreWechatSetting::CAP_MINIAPP_LOGIN,
        )->appSecret);
    }

    public function testUnconfiguredStoreFailsClosed(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);

        $this->expectException(WechatConfigurationException::class);
        $resolver->resolve(UUID::v4(), StoreWechatSetting::CAP_MINIAPP_LOGIN);
    }

    public function testDisabledCapabilityFailsClosed(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        $store = $this->createStore('A');
        $this->createStoreSetting(
            $store->getUuid(),
            $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a'),
            null,
            [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        );

        $this->expectException(WechatConfigurationException::class);
        $resolver->resolve($store->getUuid(), StoreWechatSetting::CAP_OFFICIAL_OAUTH);
    }

    public function testBindingRejectsDraftApplicationRevision(): void
    {
        $applicationId = $this->createApplication('wx-draft');
        [, $body] = $this->request('POST', '/api/v1/manage/wechat-application-revisions', [
            'application' => $applicationId,
            'secret' => 'draft-secret',
        ]);
        $draftUuid = $body['data']['uuid'];

        $store = $this->createStore('A');
        [$status, $body] = $this->request('POST', '/api/v1/manage/store-wechat-settings', [
            'storeUuid' => $store->getUuid(),
            'miniappRevision' => $draftUuid,
            'capabilities' => [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        ]);
        self::assertSame(400, $status, json_encode($body));
    }

    public function testTamperedCredentialsFailResolution(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        $store = $this->createStore('A');
        $applicationRevision = $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a');
        $this->createStoreSetting($store->getUuid(), $applicationRevision, null, [
            StoreWechatSetting::CAP_MINIAPP_LOGIN => true,
        ]);

        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE wechat_application_revision SET secret_encrypted = ? WHERE uuid = ?',
            ['v1:deadbeef:AAAA:AAAA:AAAA', $applicationRevision],
        );
        $this->entityManager()->clear();

        $this->expectException(WechatConfigurationException::class);
        $resolver->resolve($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN);
    }

    public function testClientFactoryUsesResolvedIdentityAndIsolatesCache(): void
    {
        $resolver = self::getContainer()->get(StoreWechatResolver::class);
        $factory = self::getContainer()->get(WechatClientFactory::class);
        $store = $this->createStore('A');
        $this->createStoreSetting(
            $store->getUuid(),
            $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a'),
            null,
            [StoreWechatSetting::CAP_MINIAPP_LOGIN => true],
        );

        $config = $resolver->resolve($store->getUuid(), StoreWechatSetting::CAP_MINIAPP_LOGIN);
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
            ] as $table) {
                self::assertTrue($schemaManager->tablesExist([$table]), sprintf('Missing table %s', $table));
            }

            self::assertContains('uniq_wechat_application_app_id', array_keys($schemaManager->listTableIndexes('wechat_application')));
            self::assertContains('uniq_store_wechat_setting_store_uuid', array_keys($schemaManager->listTableIndexes('store_wechat_setting')));
            self::assertContains('idx_wechat_application_revision_state', array_keys($schemaManager->listTableIndexes('wechat_application_revision')));
        } finally {
            $connection->close();
            if (is_file($databaseFile)) {
                unlink($databaseFile);
            }
        }
    }
}
