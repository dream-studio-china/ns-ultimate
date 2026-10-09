<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Payment\Controller\Webhook;


use PHPUnit\Framework\Attributes\Group;
use App\Payment\Controller\Webhook\PaymentNotifyController;
use App\Payment\Entity\Invoice;
use App\Payment\Service\Gateway\MockGateway;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class PaymentNotifyControllerTest extends TestCase
{
    private InvoiceServiceInterface $invoiceService;
    private PaymentNotifyController $controller;

    protected function setUp(): void
    {
        $this->invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $this->controller = new PaymentNotifyController(
            new PaymentGatewayRegistry([new MockGateway()]),
            $this->invoiceService,
        );
    }

    private function jsonRequest(string $outTradeNo, string $secret = 'mock'): Request
    {
        return Request::create('/api/payment/notify/mock', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'secret' => $secret,
            'outTradeNo' => $outTradeNo,
            'amount' => 100,
            'currency' => 'CNY',
            'transactionId' => 'txn-webhook',
        ], JSON_THROW_ON_ERROR));
    }

    public function testNotifyActionReturnsGatewaySuccessResponse(): void
    {
        $this->invoiceService->method('handleNotifyResult')->willReturn(new Invoice());

        $response = $this->controller->notifyAction($this->jsonRequest('PAY-1'), 'mock');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('SUCCESS', $response->getContent());
    }

    public function testNotifyActionReturnsFailForUnknownGateway(): void
    {
        $response = $this->controller->notifyAction($this->jsonRequest('PAY-1'), 'unknown');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('FAIL', $response->getContent());
    }

    public function testNotifyActionReturnsFailWithMessageForVerificationException(): void
    {
        $response = $this->controller->notifyAction($this->jsonRequest('PAY-1', 'bad'), 'mock');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringStartsWith('FAIL: Invalid mock payment secret.', $response->getContent());
    }

    public function testNotifyActionReturnsFailWhenServiceThrows(): void
    {
        $this->invoiceService->method('handleNotifyResult')->willThrowException(
            new \RuntimeException('Invoice PAY-NOT-FOUND not found.')
        );

        $response = $this->controller->notifyAction($this->jsonRequest('PAY-NOT-FOUND'), 'mock');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('FAIL', $response->getContent());
    }
}
