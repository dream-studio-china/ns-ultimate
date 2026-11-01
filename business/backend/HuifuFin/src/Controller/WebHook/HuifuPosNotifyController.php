<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\WebHook;

use App\Payment\Exception\PaymentVerificationException;
use NsUltimate\Business\HuifuFin\Service\Pos\HuifuPosNotificationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HuifuPosNotifyController
{
    public function __construct(private readonly HuifuPosNotificationService $notifications)
    {
    }

    #[Route(
        '/api/payment/notify/huifu-pos/{merchantUuid}',
        name: 'huifu-pos-notify',
        requirements: ['merchantUuid' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $merchantUuid): Response
    {
        try {
            return new Response($this->notifications->handle($request, $merchantUuid), 200, ['Content-Type' => 'text/plain']);
        } catch (PaymentVerificationException) {
            return new Response('FAIL', 400, ['Content-Type' => 'text/plain']);
        } catch (\Throwable) {
            return new Response('FAIL', 500, ['Content-Type' => 'text/plain']);
        }
    }
}
