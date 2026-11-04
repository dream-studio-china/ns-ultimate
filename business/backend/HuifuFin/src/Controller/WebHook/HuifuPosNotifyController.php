<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\WebHook;

use App\Payment\Exception\PaymentVerificationException;
use NsUltimate\Business\HuifuFin\Service\Pos\HuifuPosNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HuifuPosNotifyController
{
    public function __construct(
        private readonly HuifuPosNotificationService $notifications,
        #[Target('monolog.logger.audit')]
        private readonly LoggerInterface $logger,
    ) {
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
            $result = $this->notifications->handle($request, $merchantUuid);
            $this->audit('info', 'huifu_pos_notification_processed', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'merchantUuid' => $merchantUuid,
                'contentType' => $request->getContentTypeFormat(),
                'acknowledgement' => $result,
            ]);

            return new Response($result, 200, ['Content-Type' => 'text/plain']);
        } catch (PaymentVerificationException $exception) {
            $this->audit('warning', 'huifu_pos_notification_rejected', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'merchantUuid' => $merchantUuid,
                'reason' => $exception->getMessage(),
                'contentType' => $request->getContentTypeFormat(),
                'contentLength' => strlen($request->getContent()),
            ]);

            return new Response('FAIL', 400, ['Content-Type' => 'text/plain']);
        } catch (\Throwable $exception) {
            $this->audit('error', 'huifu_pos_notification_failed', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'merchantUuid' => $merchantUuid,
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
                'contentType' => $request->getContentTypeFormat(),
                'contentLength' => strlen($request->getContent()),
            ]);

            return new Response('FAIL', 500, ['Content-Type' => 'text/plain']);
        }
    }

    /** @param array<string, mixed> $context */
    private function audit(string $level, string $message, array $context): void
    {
        // Diagnostics must never turn a callback into another failure.
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
        }
    }
}
