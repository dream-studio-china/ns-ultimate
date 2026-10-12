<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Controller\Webhook;

use App\Payment\Exception\PaymentVerificationException;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Entity\WechatRefundAttempt;
use NsUltimate\Business\Wechat\Service\StoreWechatResolver;
use NsUltimate\Business\Wechat\Service\WechatPaymentCompletionService;
use NsUltimate\Business\Wechat\Service\WechatPayProviderInterface;
use NsUltimate\Business\Wechat\Service\WechatRefundOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Project-owned WeChat payment/refund webhook.
 *
 * The `{locator}` is the opaque UUID of a payment or refund attempt. It only
 * selects the pinned attempt configuration; verification and accounting happen
 * through the completion/orchestrator services. Success is acknowledged only
 * after the durable state transition commits.
 */
#[Route('/webhooks/wechat/notify/{locator}', name: 'wechat-webhook-notify', requirements: ['locator' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
final class WechatNotifyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreWechatResolver $resolver,
        private readonly WechatPayProviderInterface $provider,
        private readonly WechatPaymentCompletionService $completion,
        private readonly WechatRefundOrchestrator $refunds,
    ) {
    }

    public function __invoke(Request $request, string $locator): Response
    {
        try {
            $paymentAttempt = $this->entityManager->getRepository(WechatPaymentAttempt::class)->findOneBy(['uuid' => $locator]);
            if ($paymentAttempt instanceof WechatPaymentAttempt) {
                $config = $this->resolver->resolveForAttempt($paymentAttempt);
                $data = $this->provider->parseNotify($request, $config);
                $this->completion->confirmFromProviderData($paymentAttempt, $data, 'notify');

                return new JsonResponse(['code' => 'SUCCESS', 'message' => 'OK']);
            }

            $refundAttempt = $this->entityManager->getRepository(WechatRefundAttempt::class)->findOneBy(['uuid' => $locator]);
            if ($refundAttempt instanceof WechatRefundAttempt) {
                $paymentAttempt = $refundAttempt->getPaymentAttempt();
                if (!$paymentAttempt instanceof WechatPaymentAttempt) {
                    throw new PaymentVerificationException('The refund has no payment attempt.');
                }
                $config = $this->resolver->resolveForAttempt($paymentAttempt);
                $data = $this->provider->parseRefundNotify($request, $config);
                $this->refunds->confirmRefund($refundAttempt, $data, 'notify');

                return new JsonResponse(['code' => 'SUCCESS', 'message' => 'OK']);
            }

            throw new PaymentVerificationException('Unknown WeChat notify locator.');
        } catch (PaymentVerificationException|\InvalidArgumentException $e) {
            return new Response('FAIL: '.$e->getMessage(), 400, ['Content-Type' => 'text/plain']);
        } catch (\Throwable) {
            return new Response('FAIL', 400, ['Content-Type' => 'text/plain']);
        }
    }
}
