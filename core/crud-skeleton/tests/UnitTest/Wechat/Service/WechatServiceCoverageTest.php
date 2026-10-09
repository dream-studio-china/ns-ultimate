<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Service;

use App\Wechat\Service\WechatService;
use EasyWeChat\Pay\Application as PayApp;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Covers the remaining uncovered lines of App\Wechat\Service\WechatService:
 * the pay-app platform cert / public key config branches and setPayApp().
 */
#[AllowMockObjectsWithoutExpectations]
final class WechatServiceCoverageTest extends TestCase
{
    private function makeService(
        string $platformCertPath = '',
        string $pubKeyId = '',
        string $pubKeyPath = '',
    ): WechatService {
        return new WechatService(
            miniappAppId: 'wx_mini',
            miniappSecret: 'sec_mini',
            officialAppId: 'wx_off',
            officialSecret: 'sec_off',
            officialToken: 'tok',
            officialAesKey: 'aes',
            payMchId: '123',
            paySecretKey: 'sk',
            payPrivateKeyPath: '/tmp/key.pem',
            payCertificatePath: '/tmp/cert.pem',
            payPlatformCertPath: $platformCertPath,
            payPubKeyId: $pubKeyId,
            payPubKeyPath: $pubKeyPath,
        );
    }

    public function testGetPayAppAddsPlatformCertPath(): void
    {
        $service = $this->makeService(platformCertPath: 'https://example.com/platform_cert.pem');

        $app = $service->getPayApp();

        self::assertInstanceOf(PayApp::class, $app);
        self::assertContains('https://example.com/platform_cert.pem', $app->getConfig()->get('platform_certs'));
    }

    public function testGetPayAppAddsPubKeyToPlatformCerts(): void
    {
        $service = $this->makeService(pubKeyId: 'PUB_KEY_ID_001', pubKeyPath: '/tmp/pub.pem');

        $app = $service->getPayApp();

        self::assertInstanceOf(PayApp::class, $app);
        self::assertSame('/tmp/pub.pem', $app->getConfig()->get('platform_certs')['PUB_KEY_ID_001']);
    }

    public function testGetPayAppKeepsBothPlatformCertAndPubKey(): void
    {
        $service = $this->makeService(
            platformCertPath: 'https://example.com/platform_cert.pem',
            pubKeyId: 'PUB_KEY_ID_002',
            pubKeyPath: '/tmp/pub2.pem',
        );

        $app = $service->getPayApp();

        $certs = $app->getConfig()->get('platform_certs');
        self::assertContains('https://example.com/platform_cert.pem', $certs);
        self::assertSame('/tmp/pub2.pem', $certs['PUB_KEY_ID_002']);
    }

    public function testGetPayAppWithoutPlatformCerts(): void
    {
        $service = $this->makeService();

        $app = $service->getPayApp();

        self::assertInstanceOf(PayApp::class, $app);
        self::assertFalse($app->getConfig()->has('platform_certs'));
    }

    #[Group('low-value')]
    public function testSetPayAppOverridesCachedInstance(): void
    {
        $service = $this->makeService();
        $payApp = $this->createMock(PayApp::class);

        $service->setPayApp($payApp);

        self::assertSame($payApp, $service->getPayApp());
    }

    #[Group('low-value')]
    public function testGetPayAppReturnsCachedInstance(): void
    {
        $service = $this->makeService();

        $app1 = $service->getPayApp();
        $app2 = $service->getPayApp();

        self::assertSame($app1, $app2);
    }
}
