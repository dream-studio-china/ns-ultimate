<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\WebHook;

use App\Payment\Exception\PaymentVerificationException;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuNotificationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HuifuPaymentNotifyController
{
    public function __construct(private readonly HuifuNotificationService $notifications)
    {
    }

    #[Route(
        '/api/payment/notify/huifu/{attemptUuid}',
        name: 'huifu-payment-notify',
        requirements: ['attemptUuid' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $attemptUuid): Response
    {
        try {
            return new Response($this->notifications->handle($request, $attemptUuid), 200, ['Content-Type' => 'text/plain']);
        } catch (PaymentVerificationException) {
            return new Response('FAIL', 400, ['Content-Type' => 'text/plain']);
        } catch (\Throwable) {
            return new Response('FAIL', 500, ['Content-Type' => 'text/plain']);
        }
    }
}
