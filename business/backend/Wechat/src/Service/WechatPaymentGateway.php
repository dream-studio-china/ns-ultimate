<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Utils\UUID;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Store-aware WeChat Pay gateway.
 *
 * The merchant/application is selected from the Invoice's trusted Store binding
 * and pinned to a persisted attempt. Legacy/global flows are intentionally not
 * routed here; Core refunds are refused so refunds must go through the
 * project-owned orchestrator.
 */
final class WechatPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly InvoiceStoreContextAdapter $storeContext,
        private readonly StoreWechatResolver $resolver,
        private readonly WechatPaymentAttemptService $attempts,
        private readonly WechatPayProviderInterface $provider,
        private readonly WechatIdentityService $identities,
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatAuditLogger $audit,
        private readonly string $notifyUrl,
    ) {
    }

    public static function getName(): string
    {
        return Invoice::PAYMENT_WECHAT;
    }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $storeUuid = $this->storeContext->resolveStoreUuid($invoice);
        $tradeType = (string) ($options['tradeType'] ?? WechatPaymentAttempt::TRADE_JSAPI);

        [$capability, $applicationType] = match ($tradeType) {
            WechatPaymentAttempt::TRADE_JSAPI => [StoreWechatSetting::CAP_PAYMENT_JSAPI, WechatApplication::TYPE_MINIAPP],
            WechatPaymentAttempt::TRADE_OFFICIAL_JSAPI => [StoreWechatSetting::CAP_PAYMENT_OFFICIAL, WechatApplication::TYPE_OFFICIAL],
            WechatPaymentAttempt::TRADE_NATIVE => [StoreWechatSetting::CAP_PAYMENT_NATIVE, WechatApplication::TYPE_MINIAPP],
            default => throw new \InvalidArgumentException('Unsupported WeChat trade type.'),
        };

        $config = $this->resolver->resolve($storeUuid, $capability);

        $payerIdentity = null;
        if ($tradeType !== WechatPaymentAttempt::TRADE_NATIVE) {
            $payer = $invoice->getPayer();
            if ($payer === null) {
                throw new \RuntimeException('A WeChat JSAPI payment requires an authenticated payer.');
            }
            $payerIdentity = $this->identities->findForUser($payer, (string) $config->appId, $applicationType);
            if ($payerIdentity === null) {
                throw new \RuntimeException('The payer has no WeChat identity for this application.');
            }
        }

        $attempt = $this->attempts->start(
            $invoice,
            $storeUuid,
            $tradeType,
            $amount,
            $invoice->getCurrency(),
            $payerIdentity,
            (string) ($options['idempotencyKey'] ?? $invoice->getUuid()),
            $this->entityManager->getRepository(WechatApplicationRevision::class)->findOneBy(['uuid' => $config->applicationRevisionUuid]),
            $this->entityManager->getRepository(WechatMerchantRevision::class)->findOneBy(['uuid' => $config->merchantRevisionUuid]),
        );

        $partner = $config->merchantMode === WechatMerchant::MODE_PARTNER;
        $body = [
            'description' => (string) ($invoice->getSubject() ?? $invoice->getDescription() ?? 'Payment'),
            'out_trade_no' => $attempt->getProviderOrderNo(),
            'notify_url' => $this->notifyUrl.'?locator='.$attempt->getUuid(),
            'amount' => [
                'total' => $amount,
                'currency' => $invoice->getCurrency(),
            ],
        ];

        if ($partner) {
            if ($config->subMerchantId === null || $config->subMerchantId === '') {
                throw new \RuntimeException('A partner WeChat merchant requires a sub-merchant ID for this store.');
            }
            $body['sp_appid'] = (string) $config->appId;
            $body['sp_mchid'] = (string) $config->merchantId;
            $body['sub_mchid'] = $config->subMerchantId;
            if ($config->subAppId !== null && $config->subAppId !== '') {
                $body['sub_appid'] = $config->subAppId;
            }
        } else {
            $body['appid'] = (string) $config->appId;
            $body['mchid'] = (string) $config->merchantId;
        }

        if ($payerIdentity !== null) {
            $body['payer'] = $partner
                ? ['sub_openid' => $payerIdentity->getOpenid()]
                : ['openid' => $payerIdentity->getOpenid()];
        }

        $prepay = $this->provider->prepay($config, $tradeType, $body);

        $attempt->setStatus(WechatPaymentAttempt::STATUS_PENDING);
        $this->entityManager->flush();
        $this->audit->record('payment-start', 'WechatPaymentAttempt', $attempt->getUuid(), 'success', $storeUuid, [
            'tradeType' => $tradeType,
        ]);

        if ($tradeType === WechatPaymentAttempt::TRADE_NATIVE) {
            return new PaymentResult(
                invoice: $invoice,
                status: Invoice::STATUS_PAYING,
                payUrl: $prepay['code_url'] ?? null,
                qrCode: $prepay['code_url'] ?? null,
                message: 'WeChat Native order created',
            );
        }

        $jsapiAppId = $partner ? (string) ($config->subAppId ?? $config->appId) : (string) $config->appId;
        $payload = $this->provider->buildJsapiConfig($config, $tradeType, (string) ($prepay['prepay_id'] ?? ''), $jsapiAppId);

        return new PaymentResult(
            invoice: $invoice,
            status: Invoice::STATUS_PAYING,
            payload: $payload,
            message: $tradeType === WechatPaymentAttempt::TRADE_OFFICIAL_JSAPI
                ? 'WeChat Official Account JSAPI order created'
                : 'WeChat JSAPI order created',
        );
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        $locator = (string) $request->query->get('locator', '');
        if ($locator === '' || !UUID::is_valid($locator)) {
            throw new PaymentVerificationException('Missing WeChat payment locator.');
        }

        $attempt = $this->entityManager->getRepository(WechatPaymentAttempt::class)->findOneBy(['uuid' => $locator]);
        if (!$attempt instanceof WechatPaymentAttempt) {
            throw new PaymentVerificationException('Unknown WeChat payment attempt.');
        }

        $config = $this->resolver->resolveForAttempt($attempt);
        $data = $this->provider->parseNotify($request, $config);
        $this->verify($attempt, $config, $data);

        return new PaymentNotifyResult(
            payment: self::getName(),
            outTradeNo: $attempt->getProviderOrderNo(),
            status: Invoice::STATUS_PAID,
            amount: (int) ($data['amount']['total'] ?? 0),
            currency: (string) ($data['amount']['currency'] ?? 'CNY'),
            transactionId: isset($data['transaction_id']) ? (string) $data['transaction_id'] : null,
            paidAt: isset($data['success_time']) ? new \DateTimeImmutable((string) $data['success_time']) : new \DateTimeImmutable(),
            rawData: $data,
            responseBody: (string) json_encode(['code' => 'SUCCESS', 'message' => 'OK']),
        );
    }

    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        throw new \RuntimeException('Core-initiated WeChat refunds are disabled; use the store WeChat refund orchestrator.');
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        $decoded = json_decode($result->responseBody, true);

        return new JsonResponse(is_array($decoded) ? $decoded : ['code' => 'SUCCESS', 'message' => 'OK']);
    }

    /** @param array<string, mixed> $data */
    private function verify(WechatPaymentAttempt $attempt, \NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig $config, array $data): void
    {
        $orderNo = (string) ($data['out_trade_no'] ?? '');
        if ($orderNo !== $attempt->getProviderOrderNo()) {
            throw new PaymentVerificationException('WeChat notify order number does not match the attempt.');
        }

        $total = (int) ($data['amount']['total'] ?? -1);
        if ($total !== $attempt->getExpectedAmount()) {
            throw new PaymentVerificationException('WeChat notify amount does not match the attempt.');
        }

        $currency = (string) ($data['amount']['currency'] ?? '');
        if (strtoupper($currency) !== strtoupper($attempt->getCurrency())) {
            throw new PaymentVerificationException('WeChat notify currency does not match the attempt.');
        }

        if (isset($data['mchid']) && (string) $data['mchid'] !== (string) $config->merchantId) {
            throw new PaymentVerificationException('WeChat notify merchant does not match the attempt.');
        }

        if (isset($data['appid']) && (string) $data['appid'] !== (string) $config->appId) {
            throw new PaymentVerificationException('WeChat notify application does not match the attempt.');
        }

        $state = (string) ($data['trade_state'] ?? 'SUCCESS');
        if ($state !== 'SUCCESS') {
            throw new PaymentVerificationException('WeChat notify is not a successful payment.');
        }
    }
}
