<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Adapter;

use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use NsUltimate\Business\HuifuFin\Service\Payment\CustomerPaymentContext;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** Prepare through the existing composed Trade service without starting a gateway. */
#[AsDecorator(decorates: InvoiceServiceInterface::class)]
final class CustomerInvoicePreparation implements InvoiceServiceInterface
{
    public function __construct(#[AutowireDecorated] private readonly InvoiceServiceInterface $inner) {}

    public function pay(Invoice $invoice, string $payment, array $options = []): PaymentResult
    {
        $context = $options['_huifuPreparation'] ?? null;
        if ($payment === 'huifu' && $context instanceof CustomerPaymentContext) {
            if ($invoice->getStatus() !== Invoice::STATUS_PENDING || $invoice->getSourceType() !== 'trade_order'
                || $invoice->getSourceId() !== $context->orderUuid || $invoice->getScene() !== Invoice::SCENE_ORDER
                || $invoice->getPayer()?->getUuid() !== $context->payerUuid
                || $invoice->getAmount() !== $context->amount || $invoice->getCurrency() !== $context->currency) {
                throw new \RuntimeException('The prepared customer Invoice does not match the order.');
            }
            return new PaymentResult($invoice, Invoice::STATUS_PENDING);
        }
        return $this->inner->pay($invoice, $payment, $options);
    }

    public function createInvoice(CreateInvoiceRequest $request): Invoice { return $this->inner->createInvoice($request); }
    public function handleNotifyResult(PaymentNotifyResult $result): Invoice { return $this->inner->handleNotifyResult($result); }
    public function markPaid(Invoice $invoice, PaymentNotifyResult $result): Invoice { return $this->inner->markPaid($invoice, $result); }
    public function markFailed(Invoice $invoice, PaymentNotifyResult $result): Invoice { return $this->inner->markFailed($invoice, $result); }
    public function cancel(Invoice $invoice, ?string $reason = null): Invoice { return $this->inner->cancel($invoice, $reason); }
    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult { return $this->inner->refund($invoice, $amount, $reason, $options); }
    public function findBySource(string $sourceType, string $sourceId): array { return $this->inner->findBySource($sourceType, $sourceId); }
    public function get($object, bool $directly = false) { return $this->inner->get($object, $directly); }
    public function list($object = null, $order = null, bool $disableRequest = true) { return $this->inner->list($object, $order, $disableRequest); }
    public function new() { return $this->inner->new(); }
    public function update($object, ?array $data = null, bool $noFlush = false) { return $this->inner->update($object, $data, $noFlush); }
    public function remove($object): bool { return $this->inner->remove($object); }
    public function wrapInTransaction(callable $fn): mixed { return $this->inner->wrapInTransaction($fn); }
}
