<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests;

use App\Identity\Entity\User;
use NsUltimate\Business\Wechat\Entity\StoreWechatIdentity;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use PHPUnit\Framework\TestCase;

final class WechatEntityStringTest extends TestCase
{
    public function testAllWechatEntitiesHaveSafeReadableStringRepresentations(): void
    {
        $application = new WechatApplication('Mini app', WechatApplication::TYPE_MINIAPP, 'wx-app');
        $merchant = new WechatMerchant('Primary merchant', '1900000001');
        $storeUuid = 'c649259b-985e-46a7-8dcc-e03363f56db5';
        $setting = new StoreWechatSetting($storeUuid, $application, null, $merchant);
        $identity = new StoreWechatIdentity(new User(), 'wx-app', 'sensitive-openid-value', WechatApplication::TYPE_MINIAPP);

        self::assertSame('Mini app', (string) $application);
        self::assertSame('Primary merchant', (string) $merchant);
        self::assertSame('Store WeChat settings ('.$storeUuid.')', (string) $setting);
        self::assertSame(
            'WeChat miniapp identity ('.substr($identity->getUuid(), 0, 8).')',
            (string) $identity,
        );
        self::assertStringNotContainsString('sensitive-openid-value', (string) $identity);
    }
}
