<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Tests\Support;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\PaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for the frozen core WeChat gateway in delegation tests.
 */
final class FakeLegacyGateway implements PaymentGatewayInterface
{
    /** @var list<array{method: string, invoice: string, amount: int}> */
    public array $calls = [];

    public static function getName(): string
    {
        return Invoice::PAYMENT_WECHAT;
    }

    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $this->calls[] = ['method' => 'pay', 'invoice' => $invoice->getUuid(), 'amount' => $amount];

        return new PaymentResult($invoice, Invoice::STATUS_PAYING, message: 'legacy pay');
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        $this->calls[] = ['method' => 'notify', 'invoice' => '', 'amount' => 0];

        return new PaymentNotifyResult(
            payment: Invoice::PAYMENT_WECHAT,
            outTradeNo: 'legacy-order',
            status: Invoice::STATUS_PAID,
            amount: 0,
        );
    }

    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        $this->calls[] = ['method' => 'refund', 'invoice' => $invoice->getUuid(), 'amount' => $amount];

        return new PaymentRefundResult($invoice, $amount, Invoice::STATUS_REFUNDED, 'legacy-refund');
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new Response('legacy-ok');
    }
}
