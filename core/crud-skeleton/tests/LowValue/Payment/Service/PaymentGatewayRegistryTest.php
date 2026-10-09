<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Payment\Service;


use PHPUnit\Framework\Attributes\Group;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentGatewayNotFoundException;
use App\Payment\Service\PaymentGatewayInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('low-value')]
final class PaymentGatewayRegistryTest extends TestCase
{
    public function testRegistryResolvesGatewayNames(): void
    {
        $gateway = new RegistryTestGateway();
        $registry = new PaymentGatewayRegistry([$gateway]);

        self::assertTrue($registry->has('test'));
        self::assertSame($gateway, $registry->get('test'));
        self::assertSame(['test'], $registry->names());
    }

    public function testUnknownGatewayThrows(): void
    {
        $this->expectException(PaymentGatewayNotFoundException::class);
        (new PaymentGatewayRegistry([]))->get('missing');
    }
}

final class RegistryTestGateway implements PaymentGatewayInterface
{
    public static function getName(): string { return 'test'; }
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult { return new PaymentResult($invoice, Invoice::STATUS_PAYING); }
    public function notify(Request $request): PaymentNotifyResult { return new PaymentNotifyResult('test', 'x', Invoice::STATUS_PAID, 1); }
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult { return new PaymentRefundResult($invoice, $amount, Invoice::STATUS_REFUNDED); }
    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response { return new Response($result->responseBody); }
}
