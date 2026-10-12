<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests;

use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Service\WechatPayRequestFactory;
use PHPUnit\Framework\TestCase;

final class WechatPayRequestFactoryTest extends TestCase
{
    public function testDirectMerchantRequestRetainsDirectPaymentContract(): void
    {
        $merchant = new WechatMerchant('Direct', '1900000001');
        $application = new WechatApplication('Mini', WechatApplication::TYPE_MINIAPP, 'wx-sub-app', 'encrypted');
        $request = (new WechatPayRequestFactory())->createOrder($merchant, $application, 'jsapi', ['out_trade_no' => 'order-1'], 'openid-1');

        self::assertSame('v3/pay/transactions/jsapi', $request['endpoint']);
        self::assertSame([
            'out_trade_no' => 'order-1',
            'appid' => 'wx-sub-app',
            'mchid' => '1900000001',
            'payer' => ['openid' => 'openid-1'],
        ], $request['body']);
    }

    public function testServiceProviderJsapiUsesPartnerFieldsAndSubMerchantOpenId(): void
    {
        $merchant = (new WechatMerchant('Provider', '1900000001'))
            ->setPaymentMode(WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER)
            ->setServiceProviderAppId('wx-provider')
            ->setSubMerchantId('1900000002');
        $application = new WechatApplication('Sub merchant mini app', WechatApplication::TYPE_MINIAPP, 'wx-sub-app', 'encrypted');
        $request = (new WechatPayRequestFactory())->createOrder($merchant, $application, 'jsapi', ['out_trade_no' => 'order-2'], 'sub-openid');

        self::assertSame('v3/pay/partner/transactions/jsapi', $request['endpoint']);
        self::assertSame([
            'out_trade_no' => 'order-2',
            'sp_appid' => 'wx-provider',
            'sp_mchid' => '1900000001',
            'sub_mchid' => '1900000002',
            'sub_appid' => 'wx-sub-app',
            'payer' => ['sub_openid' => 'sub-openid'],
        ], $request['body']);
        self::assertArrayNotHasKey('appid', $request['body']);
        self::assertArrayNotHasKey('openid', $request['body']['payer']);
    }

    public function testServiceProviderNativeDoesNotRequireAnOpenId(): void
    {
        $merchant = (new WechatMerchant('Provider', '1900000001'))
            ->setPaymentMode(WechatMerchant::PAYMENT_MODE_SERVICE_PROVIDER)
            ->setServiceProviderAppId('wx-provider')
            ->setSubMerchantId('1900000002');
        $application = new WechatApplication('Sub merchant mini app', WechatApplication::TYPE_MINIAPP, 'wx-sub-app', 'encrypted');
        $request = (new WechatPayRequestFactory())->createOrder($merchant, $application, 'native', []);

        self::assertSame('v3/pay/partner/transactions/native', $request['endpoint']);
        self::assertArrayNotHasKey('payer', $request['body']);
    }
}
