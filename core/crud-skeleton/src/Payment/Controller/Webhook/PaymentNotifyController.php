<?php

declare(strict_types=1);

namespace App\Payment\Controller\Webhook;

use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentNotifyController extends AbstractController
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly InvoiceServiceInterface $invoiceService,
    ) {}

    #[Route('/api/payment/notify/{payment}', name: 'payment-notify', methods: ['POST'])]
    public function notifyAction(Request $request, string $payment): Response
    {
        try {
            $gateway = $this->registry->get($payment);
            $result = $gateway->notify($request);
            $this->invoiceService->handleNotifyResult($result);

            return $gateway->getNotifySuccessResponse($result);
        } catch (PaymentVerificationException $e) {
            return new Response('FAIL: ' . $e->getMessage(), 400, ['Content-Type' => 'text/plain']);
        } catch (\Throwable $e) {
            return new Response('FAIL', 400, ['Content-Type' => 'text/plain']);
        }
    }
}
