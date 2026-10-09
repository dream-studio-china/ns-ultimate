<?php

namespace App\Core\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExceptionInterceptor
{
    const EFFECTIVE_PATTERN = '/^\/(api)\/.*$/';

    private TranslatorInterface $translator;
    private LoggerInterface $logger;
    private string $env;

    public function __construct(
        TranslatorInterface $translator,
        LoggerInterface $logger,
        string $env
    ) {
        $this->translator = $translator;
        $this->logger = $logger;
        $this->env = $env;
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        // get environment
        $request = $event->getRequest();

        // check is effective url (only handle API routes)
        $result = preg_match(self::EFFECTIVE_PATTERN, $request->getPathInfo());
        if(!$result) return;

        $exception = $event->getThrowable();

        // Log the exception
        $this->logger->error(
            'Exception: ' . $request->getBasePath() . ' => ' . $exception->getMessage(),
            ['exception' => $exception]
        );

        // In dev environment, re-throw the exception to see full debug
        if('dev.disabled' === $this->env) {
            // let Symfony's default error handler show the debug page
            return;
        }

        // In production, return JSON response
        $statusCode = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : ($exception->getCode() >= 400 && $exception->getCode() < 600 ? (int) $exception->getCode() : 500);

        $responseData = [
            'code' => $statusCode,
            'message' => $this->translator->trans($exception->getMessage()),
            'class' => get_class($exception),
        ];

        // Use JsonResponse for proper JSON handling
        $jsonResponse = new JsonResponse($responseData, $statusCode);
        $event->setResponse($jsonResponse);
    }
}
