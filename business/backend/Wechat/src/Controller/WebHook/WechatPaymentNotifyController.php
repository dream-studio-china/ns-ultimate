<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\WebHook;

use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Entity\Invoice;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Service\StoreWechatPaymentGateway;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/payment/notify')]
final class WechatPaymentNotifyController
{
    public function __construct(
        private readonly StoreWechatPaymentGateway $gateway,
        private readonly InvoiceServiceInterface $invoices,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/wechat-store/{storeUuid}/{applicationUuid}/{merchantUuid}/{appType}', name: 'wechat-payment-notify', methods: ['POST'])]
    public function __invoke(Request $request, string $storeUuid, string $applicationUuid, string $merchantUuid, string $appType): JsonResponse
    {
        try {
            $result = $this->gateway->notifyForProfiles($request, $applicationUuid, $merchantUuid, $appType);
            $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['outTradeNo' => $result->outTradeNo]);
            $lifecycle = $invoice instanceof Invoice && $invoice->getSourceType() === 'trade_order'
                ? $this->entityManager->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $invoice->getSourceId()])
                : null;
            if (!$invoice instanceof Invoice || !$lifecycle instanceof OrderStoreLifecycle || $lifecycle->getStoreUuid() !== $storeUuid) {
                throw new \RuntimeException('Notification does not match a store-scoped invoice.');
            }
            $snapshot = $invoice->getExtraData()['wechat_config'] ?? null;
            if (is_array($snapshot)
                && (($snapshot['applicationUuid'] ?? null) !== $applicationUuid || ($snapshot['merchantUuid'] ?? null) !== $merchantUuid)) {
                throw new \RuntimeException('Notification credentials do not match the original payment.');
            }
            if (!is_array($snapshot)) {
                // The original pay() transaction may have rolled back after the
                // provider accepted the order. The signed callback URL carries
                // the immutable profile references needed for later refunds.
                $invoice->appendExtraData('wechat_config', [
                    'storeUuid' => $storeUuid,
                    'applicationUuid' => $applicationUuid,
                    'merchantUuid' => $merchantUuid,
                    'appType' => $appType,
                    'paymentMode' => $result->rawData['payment_mode'] ?? 'direct',
                    'serviceProviderAppId' => $result->rawData['service_provider_app_id'] ?? null,
                    'subMerchantId' => $result->rawData['sub_mchid'] ?? null,
                ]);
                $this->entityManager->flush();
            }
            $this->invoices->handleNotifyResult($result);
            return new JsonResponse(['code' => 'SUCCESS', 'message' => 'Success']);
        } catch (\Throwable) {
            return new JsonResponse(['code' => 'FAIL', 'message' => 'Notification processing failed.'], 500);
        }
    }
}
