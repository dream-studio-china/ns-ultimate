<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Core\Service\BaseServiceInterface;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;

/** @extends BaseServiceInterface<\App\Payment\Entity\Invoice> */
interface InvoiceServiceInterface extends BaseServiceInterface
{
    public function createInvoice(CreateInvoiceRequest $request): Invoice;
    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, string $payment, array $options = []): PaymentResult;
    public function handleNotifyResult(PaymentNotifyResult $result): Invoice;
    public function markPaid(Invoice $invoice, PaymentNotifyResult $result): Invoice;
    public function markFailed(Invoice $invoice, PaymentNotifyResult $result): Invoice;
    public function cancel(Invoice $invoice, ?string $reason = null): Invoice;
    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult;
    /** @return Invoice[] */
    public function findBySource(string $sourceType, string $sourceId): array;
}
