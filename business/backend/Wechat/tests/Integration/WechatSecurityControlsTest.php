<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Integration;

use App\Core\Utils\UUID;
use Doctrine\ORM\OptimisticLockException;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatProfileGrant;
use NsUltimate\Business\Wechat\Service\WechatApplicationRevisionService;
use NsUltimate\Business\Wechat\Service\WechatAuditLogger;
use NsUltimate\Business\Wechat\Service\WechatCredentialProbe;
use NsUltimate\Business\Wechat\Service\WechatProfileGrantService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Route;

final class WechatSecurityControlsTest extends WechatApiTestCase
{
    public function testProfileGrantRouteIsRegistered(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();
        self::assertInstanceOf(Route::class, $routes->get('manage-wechat-profile-grants-list'));
        self::assertSame('/manage/wechat-profile-grants', $routes->get('manage-wechat-profile-grants-list')?->getPath());
        self::assertInstanceOf(Route::class, $routes->get('api-v1-manage-wechat-profile-grants-list'));
    }

    public function testGrantEnforcementRequiresExplicitStoreGrant(): void
    {
        $grants = self::getContainer()->get(WechatProfileGrantService::class);
        $storeUuid = UUID::v4();
        $profileUuid = UUID::v4();

        $this->expectException(\InvalidArgumentException::class);
        $grants->assertGranted($storeUuid, WechatProfileGrant::TYPE_APPLICATION, $profileUuid);
    }

    public function testGrantCreationIsAuditedAndEnablesStoreBinding(): void
    {
        $store = $this->createStore('A');
        $applicationId = $this->createApplication('wx-mini-a');
        $applicationUuid = $this->entityManager()->getRepository(WechatApplication::class)->find($applicationId)?->getUuid();

        [$status, $body] = $this->request('POST', '/api/v1/manage/wechat-profile-grants', [
            'profileType' => WechatProfileGrant::TYPE_APPLICATION,
            'profileUuid' => $applicationUuid,
            'storeUuid' => $store->getUuid(),
        ]);
        self::assertSame(201, $status, json_encode($body));

        $grants = self::getContainer()->get(WechatProfileGrantService::class);
        self::assertTrue($grants->isGranted($store->getUuid(), WechatProfileGrant::TYPE_APPLICATION, (string) $applicationUuid));

        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM wechat_audit_event WHERE action = 'grant-create'",
        ));
    }

    public function testGrantRejectsUnknownProfile(): void
    {
        $store = $this->createStore('A');
        [$status] = $this->request('POST', '/api/v1/manage/wechat-profile-grants', [
            'profileType' => WechatProfileGrant::TYPE_APPLICATION,
            'profileUuid' => UUID::v4(),
            'storeUuid' => $store->getUuid(),
        ]);
        self::assertSame(400, $status);
    }

    public function testPublicationIsAuditedWithoutSecrets(): void
    {
        $revisionUuid = $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'super-secret-value');

        $row = $this->entityManager()->getConnection()->fetchAssociative(
            "SELECT action, object_type, context FROM wechat_audit_event WHERE action = 'publish'",
        );
        self::assertIsArray($row);
        self::assertSame('WechatApplicationRevision', $row['object_type']);
        self::assertStringNotContainsString('super-secret-value', (string) $row['context']);
    }

    public function testAuditLoggerRedactsSensitiveContext(): void
    {
        self::getContainer()->get(WechatAuditLogger::class)->record('test', 'Widget', UUID::v4(), 'success', null, [
            'secret' => 'plain-text-secret',
            'sessionKey' => 'session',
            'name' => 'visible',
        ]);

        $context = json_decode((string) $this->entityManager()->getConnection()->fetchOne(
            "SELECT context FROM wechat_audit_event WHERE action = 'test'",
        ), true);
        self::assertSame('[redacted]', $context['secret']);
        self::assertSame('[redacted]', $context['sessionKey']);
        self::assertSame('visible', $context['name']);
    }

    public function testConcurrentPublicationRaisesConflict(): void
    {
        $applicationId = $this->createApplication('wx-mini-a');
        [, $body] = $this->request('POST', '/api/v1/manage/wechat-application-revisions', [
            'application' => $applicationId,
            'secret' => 'secret-a',
        ]);
        $revisionUuid = $body['data']['uuid'];

        $service = self::getContainer()->get(WechatApplicationRevisionService::class);
        $revision = $this->entityManager()->getRepository(WechatApplicationRevision::class)->findOneBy(['uuid' => $revisionUuid]);
        self::assertInstanceOf(WechatApplicationRevision::class, $revision);

        // Simulate a concurrent update: the persisted version moves ahead of the in-memory entity.
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE wechat_application_revision SET lock_version = lock_version + 1 WHERE id = ?',
            [$revision->getId()],
        );

        try {
            $service->publish($revision);
            self::fail('Expected a conflict.');
        } catch (ConflictHttpException) {
            self::assertTrue(true);
        } catch (OptimisticLockException) {
            self::assertTrue(true);
        }
    }

    public function testCredentialProbeReportsReadinessOffline(): void    {
        $probe = self::getContainer()->get(WechatCredentialProbe::class);
        $revisionUuid = $this->publishApplicationRevision($this->createApplication('wx-mini-a'), 'secret-a');

        $result = $probe->probeApplication($revisionUuid);
        self::assertTrue($result['ready']);
        self::assertTrue($result['checks']['secret']);

        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM wechat_audit_event WHERE action = 'probe'",
        ));
    }

    public function testPartnerBindingRequiresSubMerchant(): void
    {
        $store = $this->createStore('PAY');
        $applicationRevision = $this->publishApplicationRevision($this->createApplication('wx-mini-pay'), 'secret-pay');
        [, $merchant] = $this->request('POST', '/api/v1/manage/wechat-merchants', [
            'name' => 'Partner merchant',
            'merchantId' => '1900000002',
            'mode' => 'partner',
        ]);
        [, $revision] = $this->request('POST', '/api/v1/manage/wechat-merchant-revisions', [
            'merchant' => $merchant['data']['id'],
            'apiV3Key' => str_repeat('k', 32),
            'privateKey' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'certificate' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
        ]);
        $this->request('PUT', '/api/v1/manage/wechat-merchant-revisions/'.$revision['data']['uuid'], ['state' => 'published']);

        [$status] = $this->request('POST', '/api/v1/manage/store-wechat-settings', [
            'storeUuid' => $store->getUuid(),
            'miniappRevision' => $applicationRevision,
            'merchantRevision' => $revision['data']['uuid'],
            'capabilities' => [\NsUltimate\Business\Wechat\Entity\StoreWechatSetting::CAP_PAYMENT_NATIVE => true],
        ]);
        self::assertSame(400, $status);

        [$status] = $this->request('POST', '/api/v1/manage/store-wechat-settings', [
            'storeUuid' => $store->getUuid(),
            'miniappRevision' => $applicationRevision,
            'merchantRevision' => $revision['data']['uuid'],
            'subMerchantId' => '1900000003',
            'capabilities' => [\NsUltimate\Business\Wechat\Entity\StoreWechatSetting::CAP_PAYMENT_NATIVE => true],
        ]);
        self::assertSame(201, $status);
    }
}
