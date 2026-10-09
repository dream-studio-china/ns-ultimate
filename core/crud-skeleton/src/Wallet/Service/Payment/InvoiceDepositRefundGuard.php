<?php

declare(strict_types=1);

namespace App\Wallet\Service\Payment;

use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Wallet\Entity\Voucher;
use App\Wallet\Repository\VoucherRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Guards invoice refunds against an active invoice-backed wallet deposit.
 *
 * An invoice whose paid amount has been credited to a wallet through an APPLIED
 * `invoice` voucher cannot be refunded until that voucher is reversed. The
 * guard is derived purely from the voucher status (no invoice state change):
 * a reversed voucher re-opens the refund path automatically.
 *
 * Decorates {@see \App\Payment\Service\InvoiceService} so every refund entry
 * point (Payment Manage/App controllers and Trade order refunds) goes through
 * this check.
 */
final class InvoiceDepositRefundGuard implements InvoiceServiceInterface
{
    public function __construct(
        private readonly InvoiceServiceInterface $inner,
        private readonly VoucherRepository $voucherRepository,
    ) {
    }

    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult
    {
        $voucher = $this->voucherRepository->findByVoucherSource(Voucher::VOUCHER_TYPE_INVOICE, $invoice->getUuid());
        if ($voucher !== null && $voucher->getStatus() === Voucher::STATUS_APPLIED) {
            throw new \RuntimeException('Invoice has an active wallet deposit voucher; reverse the deposit before refunding.');
        }

        return $this->inner->refund($invoice, $amount, $reason, $options);
    }

    public function get($object, bool $directly = false)
    {
        return $this->inner->get($object, $directly);
    }

    public function list($object = null, $order = null, bool $disableRequest = true)
    {
        return $this->inner->list($object, $order, $disableRequest);
    }

    public function new()
    {
        return $this->inner->new();
    }

    public function update($object, ?array $data = null, bool $noFlush = false)
    {
        return $this->inner->update($object, $data, $noFlush);
    }

    public function remove($object): bool
    {
        return $this->inner->remove($object);
    }

    public function wrapInTransaction(callable $fn): mixed
    {
        return $this->inner->wrapInTransaction($fn);
    }

    public function createInvoice(CreateInvoiceRequest $request): Invoice
    {
        return $this->inner->createInvoice($request);
    }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, string $payment, array $options = []): PaymentResult
    {
        return $this->inner->pay($invoice, $payment, $options);
    }

    public function handleNotifyResult(PaymentNotifyResult $result): Invoice
    {
        return $this->inner->handleNotifyResult($result);
    }

    public function markPaid(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        return $this->inner->markPaid($invoice, $result);
    }

    public function markFailed(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        return $this->inner->markFailed($invoice, $result);
    }

    public function cancel(Invoice $invoice, ?string $reason = null): Invoice
    {
        return $this->inner->cancel($invoice, $reason);
    }

    /**
     * @return Invoice[]
     */
    public function findBySource(string $sourceType, string $sourceId): array
    {
        return $this->inner->findBySource($sourceType, $sourceId);
    }
}