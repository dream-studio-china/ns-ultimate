<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use App\Trade\Entity\OrderStoreLifecycle;
use EasyWeChat\Pay\Client as WechatPayClient;
use EasyWeChat\Pay\Server as WechatPayServer;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class StoreWechatPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreWechatResolver $resolver,
        private readonly WechatClientFactory $clients,
        private readonly WechatPayRequestFactory $paymentRequests,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getName(): string
    {
        return Invoice::PAYMENT_WECHAT;
    }

    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $lifecycle = $this->orderLifecycle($invoice);
        $setting = $this->resolver->setting($lifecycle->getStoreUuid());
        $identity = null;
        $type = $setting->getMiniapp() !== null ? 'miniapp' : 'official';
        $application = $setting->getMiniapp() ?? $setting->getOfficial();
        if ($application === null) {
            throw new \RuntimeException('Store has no WeChat application configured.');
        }
        if ($invoice->getTradeType() !== 'native') {
            $payer = $invoice->getPayer() ?? throw new \RuntimeException('The payer must sign in with WeChat before payment.');
            $identityRepository = $this->entityManager->getRepository(\NsUltimate\Business\Wechat\Entity\StoreWechatIdentity::class);
            $miniapp = $setting->getMiniapp();
            $official = $setting->getOfficial();
            $identity = $miniapp !== null
                ? $identityRepository->findOneBy(['user' => $payer, 'appId' => $miniapp->getAppId()])
                : null;
            $type = 'miniapp';
            $application = $miniapp;
            if ($identity === null && $official !== null) {
                $identity = $identityRepository->findOneBy(['user' => $payer, 'appId' => $official->getAppId()]);
                $type = 'official';
                $application = $official;
            }
            if ($identity === null) {
                throw new \RuntimeException('The payer must sign in with this Store WeChat application before payment.');
            }
            if ($application === null) {
                throw new \RuntimeException(sprintf('Store has no %s WeChat application configured.', $type));
            }
        }
        $merchant = $setting->getMerchant()
            ?? throw new \RuntimeException('Store has no WeChat Pay merchant configured.');
        $tradeType = $invoice->getTradeType() ?? 'jsapi';
        $notifyUrl = $this->urlGenerator->generate('wechat-payment-notify', [
            'storeUuid' => $lifecycle->getStoreUuid(),
            'applicationUuid' => $application->getUuid(),
            'merchantUuid' => $merchant->getUuid(),
            'appType' => $type,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $app = $this->clients->payByUuid($application->getUuid(), $merchant->getUuid(), $type);
        $commonFields = [
            'description' => $invoice->getSubject() ?? $invoice->getDescription() ?? 'Payment',
            'notify_url' => $notifyUrl,
            'amount' => ['total' => $amount, 'currency' => $invoice->getCurrency()],
            'out_trade_no' => $invoice->getOutTradeNo(),
        ];

        $invoice->appendExtraData('wechat_config', [
            'storeUuid' => $lifecycle->getStoreUuid(),
            'applicationUuid' => $application->getUuid(),
            'merchantUuid' => $merchant->getUuid(),
            'appType' => $type,
            'paymentMode' => $merchant->getPaymentMode(),
            'serviceProviderAppId' => $merchant->getServiceProviderAppId(),
            'subMerchantId' => $merchant->getSubMerchantId(),
        ]);

        if ($tradeType === 'native') {
            $request = $this->paymentRequests->createOrder($merchant, $application, $tradeType, $commonFields);
            $result = $this->postJson($this->payClient($app), $request['endpoint'], $request['body'])->toArray(false);
            return new PaymentResult($invoice, Invoice::STATUS_PAYING, payUrl: $result['code_url'] ?? null, qrCode: $result['code_url'] ?? null, message: 'WeChat Native order created');
        }
        if ($tradeType !== 'jsapi') {
            throw new \InvalidArgumentException(sprintf('Unsupported WeChat trade type: %s', $tradeType));
        }

        $request = $this->paymentRequests->createOrder($merchant, $application, $tradeType, $commonFields, $identity?->getOpenid());
        $result = $this->postJson($this->payClient($app), $request['endpoint'], $request['body'])->toArray(false);
        $payload = $type === 'official'
            ? $app->getUtils()->buildSdkConfig((string) ($result['prepay_id'] ?? ''), $application->getAppId())
            : $app->getUtils()->buildMiniAppConfig((string) ($result['prepay_id'] ?? ''), $application->getAppId());
        return new PaymentResult($invoice, Invoice::STATUS_PAYING, payload: $payload, message: 'WeChat JSAPI order created');
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        throw new PaymentVerificationException('Use the store-specific WeChat notification route.');
    }

    public function notifyForProfiles(Request $request, string $applicationUuid, string $merchantUuid, string $type = 'miniapp'): PaymentNotifyResult
    {
        $type = $type === 'official' ? 'official' : 'miniapp';
        $application = $this->resolver->applicationByUuid($applicationUuid, $type)['profile'];
        $merchant = $this->resolver->merchantByUuid($merchantUuid)['profile'];
        $app = $this->clients->payByUuid($applicationUuid, $merchantUuid, $type);
        try {
            $server = $this->payServer($app);
            $notifyResult = null;
            $psrRequest = $this->psrHttpFactory->createRequest($request);
            $server->handlePaid(function ($message, \Closure $next) use ($app, $application, $merchant, $psrRequest, &$notifyResult) {
                $app->getValidator()->validate($psrRequest);
                if (!$this->paymentRequests->notificationMatchesProfiles($merchant, $application, $message)) {
                    throw new PaymentVerificationException('WeChat notification app or merchant does not match its configured profile.');
                }
                $notifyResult = new PaymentNotifyResult(
                    payment: self::getName(),
                    outTradeNo: (string) ($message->out_trade_no ?? ''),
                    status: Invoice::STATUS_PAID,
                    amount: (int) ($message->amount['total'] ?? 0),
                    currency: (string) ($message->amount['currency'] ?? 'CNY'),
                    transactionId: $message->transaction_id ?? null,
                    paidAt: isset($message->success_time) ? new \DateTimeImmutable($message->success_time) : new \DateTimeImmutable(),
                    rawData: [
                        'appid' => $message->appid ?? $message->sub_appid ?? $application->getAppId(),
                        'mchid' => $message->mchid ?? $message->sp_mchid ?? $merchant->getMerchantId(),
                        'payment_mode' => $merchant->getPaymentMode(),
                        'service_provider_app_id' => $merchant->getServiceProviderAppId(),
                        'sub_mchid' => $message->sub_mchid ?? null,
                        'out_trade_no' => (string) ($message->out_trade_no ?? ''),
                        'transaction_id' => $message->transaction_id ?? null,
                    ],
                    responseBody: (string) json_encode(['code' => 'SUCCESS', 'message' => 'Success']),
                );
                return $next($message);
            });
            $response = $server->serve();
            if ($notifyResult instanceof PaymentNotifyResult) {
                return $notifyResult;
            }
            throw new PaymentVerificationException(sprintf('WeChat notification rejected: HTTP %d.', $response->getStatusCode()));
        } catch (PaymentVerificationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PaymentVerificationException('WeChat notification verification failed.', 0, $exception);
        }
    }

    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        $context = $invoice->getExtraData()['wechat_config'] ?? null;
        if (!is_array($context) || !is_string($context['applicationUuid'] ?? null) || !is_string($context['merchantUuid'] ?? null)) {
            throw new \RuntimeException('The original WeChat payment configuration is unavailable for refund.');
        }
        $type = ($context['appType'] ?? null) === 'official' ? 'official' : 'miniapp';
        $app = $this->clients->payByUuid($context['applicationUuid'], $context['merchantUuid'], $type);
        $outRefundNo = 'RF'.substr(hash('sha256', implode(':', [
            $invoice->getUuid(),
            (string) $invoice->getRefundedAmount(),
            (string) $amount,
        ])), 0, 30);
        $refundBody = $this->paymentRequests->createRefundBody($this->resolver->merchantByUuid($context['merchantUuid'])['profile'], [
            'out_trade_no' => $invoice->getOutTradeNo(),
            'out_refund_no' => $outRefundNo,
            'reason' => $reason,
            'amount' => ['refund' => $amount, 'total' => $paidAmount, 'currency' => $invoice->getCurrency()],
        ]);
        $result = $this->postJson($this->payClient($app), 'v3/refund/domestic/refunds', $refundBody)->toArray(false);
        if (($result['status'] ?? '') !== 'SUCCESS') {
            throw new \RuntimeException('WeChat has not confirmed the refund. Recheck the same refund before retrying.');
        }
        if (isset($result['amount']['refund']) && (int) $result['amount']['refund'] !== $amount) {
            throw new \RuntimeException('WeChat refund amount does not match the requested amount.');
        }
        if (isset($result['amount']['total']) && (int) $result['amount']['total'] !== $paidAmount) {
            throw new \RuntimeException('WeChat refund total does not match the paid amount.');
        }
        $status = $amount >= $paidAmount - $invoice->getRefundedAmount()
            ? Invoice::STATUS_REFUNDED
            : Invoice::STATUS_PARTIAL_REFUNDED;

        return new PaymentRefundResult($invoice, $amount, $status, $result['refund_id'] ?? null, $result);
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new JsonResponse(['code' => 'SUCCESS', 'message' => 'Success']);
    }

    private function orderLifecycle(Invoice $invoice): OrderStoreLifecycle
    {
        if ($invoice->getSourceType() !== 'trade_order') {
            throw new \RuntimeException('Store WeChat payments require a store-scoped trade order.');
        }
        $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $invoice->getSourceId()]);
        if (!$lifecycle instanceof OrderStoreLifecycle) {
            throw new \RuntimeException('The trade order has no trusted store context.');
        }
        return $lifecycle;
    }

    /** @param array<string, mixed> $body */
    private function postJson(WechatPayClient $client, string $path, array $body): ResponseInterface
    {
        return $client->postJson($path, $body);
    }

    private function payClient(\EasyWeChat\Pay\Application $app): WechatPayClient
    {
        $client = $app->getClient();
        if (!$client instanceof WechatPayClient) {
            throw new \RuntimeException('WeChat Pay client is unavailable.');
        }
        return $client;
    }

    private function payServer(\EasyWeChat\Pay\Application $app): WechatPayServer
    {
        $server = $app->getServer();
        if (!$server instanceof WechatPayServer) {
            throw new \RuntimeException('WeChat Pay server is unavailable.');
        }
        return $server;
    }
}
