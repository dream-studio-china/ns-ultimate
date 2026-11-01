<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Payment\Entity\Invoice;
use App\Wechat\Repository\WechatUserRepository;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class HuifuRequestFactory
{
    public function __construct(
        private readonly HuifuSigner $signer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly WechatUserRepository $wechatUsers,
        private readonly StoreWechatResolver $wechatResolver,
    ) {
    }

    /** @param array<string, string> $credentials
     *  @param array<string, mixed> $options
     *  @return array<string, mixed> */
    public function paymentPayload(
        Invoice $invoice,
        HuifuPaymentAttempt $attempt,
        HuifuProviderProfile $provider,
        array $credentials,
        array $options = [],
    ): array {
        $tradeType = $attempt->getTradeType();
        $notifyUrl = $this->urlGenerator->generate(
            'huifu-payment-notify',
            ['attemptUuid' => $attempt->getUuid()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $goodsDesc = $invoice->getSubject() ?? $invoice->getDescription() ?? 'Payment';
        $amount = number_format($attempt->getAmount() / 100, 2, '.', '');

        $data = match ($attempt->getChannel()) {
            'jspay', 'native' => [
                'req_date' => $attempt->getReqDate(),
                'req_seq_id' => $attempt->getReqSeqId(),
                'huifu_id' => $attempt->getHuifuId(),
                'trade_type' => $tradeType,
                'trans_amt' => $amount,
                'goods_desc' => mb_substr($goodsDesc, 0, 120),
                'notify_url' => $notifyUrl,
            ],
            'quickpay' => [
                'req_date' => $attempt->getReqDate(),
                'req_seq_id' => $attempt->getReqSeqId(),
                'huifu_id' => $attempt->getHuifuId(),
                'trans_amt' => $amount,
                'notify_url' => $notifyUrl,
                'extend_pay_data' => json_encode([
                    'goods_short_name' => mb_substr($goodsDesc, 0, 32),
                    'biz_tp' => '100001',
                    'gw_chnnl_tp' => '02',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'terminal_device_data' => json_encode([
                    'device_type' => '1',
                    'device_ip' => (string) ($options['clientIp'] ?? ''),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'risk_check_data' => json_encode([
                    'ip_addr' => (string) ($options['clientIp'] ?? ''),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
            default => throw new \InvalidArgumentException(sprintf('Unsupported Huifu payment channel "%s".', $attempt->getChannel())),
        };

        if (in_array($tradeType, ['T_JSAPI', 'T_MINIAPP'], true)) {
            $data['wx_data'] = json_encode([
                'openid' => $this->openid($invoice, $attempt->getStoreUuid(), $tradeType),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        ksort($data);
        $sign = $this->signer->sign($data, $credentials['merchant_private_key']);

        return [
            'sys_id' => $provider->getSysId(),
            'product_id' => $provider->getProductId(),
            'data' => $data,
            'sign' => $sign,
        ];
    }

    private function openid(Invoice $invoice, string $storeUuid, string $tradeType): string
    {
        $payer = $invoice->getPayer();
        if ($payer === null) {
            throw new \RuntimeException('The payer must sign in with WeChat before Huifu JSAPI payment.');
        }
        $setting = $this->wechatResolver->setting($storeUuid);
        $application = $tradeType === 'T_MINIAPP'
            ? ($setting->getMiniapp() ?? $setting->getOfficial())
            : ($setting->getOfficial() ?? $setting->getMiniapp());
        if ($application === null) {
            throw new \RuntimeException('Store has no WeChat application configured for Huifu JSAPI payment.');
        }
        $identity = $this->wechatUsers->findByUserAndAppId($payer, $application->getAppId());
        if ($identity === null) {
            throw new \RuntimeException('The payer must sign in with this Store WeChat application before payment.');
        }

        return $identity->getOpenid();
    }
}
