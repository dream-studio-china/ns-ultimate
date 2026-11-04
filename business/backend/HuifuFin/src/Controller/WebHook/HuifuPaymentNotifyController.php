<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Controller\WebHook;

use App\Payment\Exception\PaymentVerificationException;
use NsUltimate\Business\HuifuFin\Service\Payment\HuifuNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HuifuPaymentNotifyController
{
    public function __construct(
        private readonly HuifuNotificationService $notifications,
        #[Target('monolog.logger.audit')]
        private readonly LoggerInterface $logger,
    ) {
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
            $result = $this->notifications->handle($request, $attemptUuid);
            $this->audit('info', 'huifu_notification_processed', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'attemptUuid' => $attemptUuid,
                'contentType' => $request->getContentTypeFormat(),
                'acknowledgement' => $result,
            ]);

            return new Response($result, 200, ['Content-Type' => 'text/plain']);
        } catch (PaymentVerificationException $exception) {
            // Record the precise verification failure; the HTTP body intentionally
            // stays a generic FAIL so Huifu retries and callers learn nothing.
            $this->audit('warning', 'huifu_notification_rejected', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'attemptUuid' => $attemptUuid,
                'reason' => $exception->getMessage(),
                'contentType' => $request->getContentTypeFormat(),
                'contentLength' => strlen($request->getContent()),
                'fields' => $this->fieldNames($request),
            ]);

            return new Response('FAIL', 400, ['Content-Type' => 'text/plain']);
        } catch (\Throwable $exception) {
            $this->audit('error', 'huifu_notification_failed', [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'attemptUuid' => $attemptUuid,
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

    /** @return list<string> */
    private function fieldNames(Request $request): array
    {
        $fields = array_keys($request->request->all());
        if ($fields !== []) {
            return array_map(strval(...), $fields);
        }
        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded)) {
            return array_map(strval(...), array_keys($decoded));
        }
        parse_str($request->getContent(), $parsed);

        return array_map(strval(...), array_keys($parsed));
    }
}
