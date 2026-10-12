<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Confirms WeChat payments from notify payloads and provider queries.
 *
 * Every confirmation goes through the same verification (order number, amount,
 * currency, merchant, application, success state) before any accounting, including
 * duplicates. The attempt transition is applied with a conditional update so
 * concurrent deliveries confirm accounting exactly once. Core accounting is
 * applied through InvoiceService::handleNotifyResult, which is idempotent for
 * already-paid invoices.
 */
final class WechatPaymentCompletionService
{
    public function __construct(
        private readonly StoreWechatResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceProviderInterface $invoiceServices,
        private readonly WechatAuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed> $providerData */
    public function confirmFromProviderData(WechatPaymentAttempt $attempt, array $providerData, string $source): PaymentNotifyResult
    {
        $this->entityManager->beginTransaction();
        try {
            $result = $this->confirmInTransaction($attempt, $providerData, $source);
            $this->entityManager->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $providerData */
    private function confirmInTransaction(WechatPaymentAttempt $attempt, array $providerData, string $source): PaymentNotifyResult
    {
        $config = $this->resolver->resolveForAttempt($attempt);
        $this->verify($attempt, $config, $providerData);

        $result = new PaymentNotifyResult(
            payment: Invoice::PAYMENT_WECHAT,
            outTradeNo: $attempt->getProviderOrderNo(),
            status: Invoice::STATUS_PAID,
            amount: (int) ($providerData['amount']['total'] ?? 0),
            currency: strtoupper((string) ($providerData['amount']['currency'] ?? 'CNY')),
            transactionId: isset($providerData['transaction_id']) ? (string) $providerData['transaction_id'] : null,
            paidAt: isset($providerData['success_time'])
                ? new \DateTimeImmutable((string) $providerData['success_time'])
                : new \DateTimeImmutable(),
            rawData: $providerData,
            responseBody: (string) json_encode(['code' => 'SUCCESS', 'message' => 'OK']),
        );

        // Flush any pending entity state first so the refresh() below cannot
        // discard unflushed in-memory changes.
        $this->entityManager->flush();
        $applied = $this->entityManager->getConnection()->executeStatement(
            "UPDATE wechat_payment_attempt SET status = 'succeeded', transaction_id = :transactionId WHERE id = :id AND status IN ('created', 'pending', 'unknown')",
            [
                'transactionId' => $result->transactionId,
                'id' => $attempt->getId(),
            ],
        );

        if ($applied === 1) {
            $this->entityManager->refresh($attempt);
            $invoice = $this->requireInvoice($attempt);
            $this->invoices()->handleNotifyResult($result);
            $this->audit->record('payment-confirm', 'WechatPaymentAttempt', $attempt->getUuid(), 'success', $attempt->getStoreUuid(), [
                'source' => $source,
                'transactionId' => $result->transactionId,
            ]);

            return $result;
        }

        $this->entityManager->refresh($attempt);
        if ($attempt->getStatus() === WechatPaymentAttempt::STATUS_SUCCEEDED) {
            // A concurrent delivery already confirmed this attempt; do not re-apply accounting.
            return $result;
        }

        throw new PaymentVerificationException('The payment attempt is no longer awaiting confirmation.');
    }

    public function lock(WechatPaymentAttempt $attempt): void
    {
        $this->entityManager->lock($attempt, LockMode::PESSIMISTIC_WRITE);
    }

    private function invoices(): InvoiceServiceInterface
    {
        $service = $this->invoiceServices->get('invoice');
        if (!$service instanceof InvoiceServiceInterface) {
            throw new \RuntimeException('The invoice service is not available.');
        }

        return $service;
    }

    private function requireInvoice(WechatPaymentAttempt $attempt): Invoice
    {
        $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['uuid' => $attempt->getInvoiceUuid()]);
        if (!$invoice instanceof Invoice) {
            throw new PaymentVerificationException('The invoice for this payment attempt was not found.');
        }
        if ($invoice->getOutTradeNo() !== $attempt->getProviderOrderNo()) {
            throw new PaymentVerificationException('The invoice order number does not match the payment attempt.');
        }

        return $invoice;
    }

    /** @param array<string, mixed> $data */
    private function verify(WechatPaymentAttempt $attempt, ResolvedWechatConfig $config, array $data): void
    {
        $orderNo = (string) ($data['out_trade_no'] ?? '');
        if ($orderNo !== $attempt->getProviderOrderNo()) {
            throw new PaymentVerificationException('WeChat notify order number does not match the attempt.');
        }

        $total = (int) ($data['amount']['total'] ?? -1);
        if ($total !== $attempt->getExpectedAmount()) {
            throw new PaymentVerificationException('WeChat notify amount does not match the attempt.');
        }

        $currency = (string) ($data['amount']['currency'] ?? '');
        if (strtoupper($currency) !== strtoupper($attempt->getCurrency())) {
            throw new PaymentVerificationException('WeChat notify currency does not match the attempt.');
        }

        if (isset($data['mchid']) && (string) $data['mchid'] !== (string) $config->merchantId) {
            throw new PaymentVerificationException('WeChat notify merchant does not match the attempt.');
        }

        if (isset($data['appid']) && (string) $data['appid'] !== (string) $config->appId) {
            throw new PaymentVerificationException('WeChat notify application does not match the attempt.');
        }

        $state = (string) ($data['trade_state'] ?? 'SUCCESS');
        if ($state !== 'SUCCESS') {
            throw new PaymentVerificationException(sprintf('WeChat reports the payment as "%s", not successful.', $state));
        }
    }
}
