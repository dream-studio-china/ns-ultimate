<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Tests\Unit;

use NsUltimate\Business\HuifuFin\Client\HuifuEndpoints;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuPaymentChannel;
use PHPUnit\Framework\TestCase;

final class HuifuPaymentChannelTest extends TestCase
{
    public function testAlipayIsAdditiveAndNotEnabledByDefault(): void
    {
        $merchant = new HuifuMerchant();
        $setting = new StoreHuifuSetting();
        self::assertFalse($merchant->allows('alipay'));
        self::assertSame(['jspay'], $setting->getAllowedMethods());
        self::assertSame('jspay', $setting->getDefaultMethod());
        self::assertSame('T_JSAPI', HuifuPaymentChannel::tradeType('jspay'));
        self::assertSame('T_JSAPI', HuifuPaymentChannel::tradeType('quickpay'));
        self::assertSame('T_NATIVE', HuifuPaymentChannel::tradeType('native'));
        self::assertSame('A_NATIVE', HuifuPaymentChannel::tradeType('alipay'));
        self::assertSame(HuifuEndpoints::JSPAY, HuifuEndpoints::forChannel('alipay'));
    }

    public function testUnsupportedChannelsCannotFallBackToWeChat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HuifuPaymentChannel::tradeType('unknown');
    }
}
