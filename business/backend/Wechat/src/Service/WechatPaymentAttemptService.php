<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Payment\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatIdentity;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;

/**
 * Creates and de-duplicates persisted WeChat payment attempts.
 *
 * One unresolved external collection attempt is allowed per Invoice; a repeated
 * idempotency key with identical inputs reuses the attempt, and conflicting
 * inputs are rejected.
 */
final class WechatPaymentAttemptService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function start(
        Invoice $invoice,
        string $storeUuid,
        string $tradeType,
        int $amount,
        string $currency,
        ?WechatIdentity $payerIdentity,
        string $idempotencyKey,
        ?WechatApplicationRevision $applicationRevision = null,
        ?WechatMerchantRevision $merchantRevision = null,
        ?string $subMerchantId = null,
        ?string $subAppId = null,
    ): WechatPaymentAttempt {
        if (!in_array($tradeType, [WechatPaymentAttempt::TRADE_JSAPI, WechatPaymentAttempt::TRADE_NATIVE, WechatPaymentAttempt::TRADE_OFFICIAL_JSAPI], true)) {
            throw new \InvalidArgumentException('Unsupported WeChat trade type.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('The external payment amount must be positive.');
        }
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('An idempotency key is required.');
        }

        $repository = $this->entityManager->getRepository(WechatPaymentAttempt::class);

        $existing = $repository->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
        if ($existing instanceof WechatPaymentAttempt) {
            if ($existing->getInvoiceUuid() !== $invoice->getUuid()
                || $existing->getExpectedAmount() !== $amount
                || $existing->getTradeType() !== $tradeType
                || $existing->getStoreUuid() !== $storeUuid) {
                throw new \InvalidArgumentException('The idempotency key was reused with different payment inputs.');
            }
            $this->pinRevisions($existing, $applicationRevision, $merchantRevision);

            return $existing;
        }

        $byInvoice = $repository->findOneBy(['invoiceUuid' => $invoice->getUuid()]);
        if ($byInvoice instanceof WechatPaymentAttempt) {
            if (!$byInvoice->isUnresolved()) {
                throw new \InvalidArgumentException('The invoice already has a completed payment attempt.');
            }
            if ($byInvoice->getExpectedAmount() !== $amount || $byInvoice->getTradeType() !== $tradeType) {
                throw new \InvalidArgumentException('The invoice already has an unresolved attempt with different inputs.');
            }
            $this->pinRevisions($byInvoice, $applicationRevision, $merchantRevision);

            return $byInvoice;
        }

        $attempt = (new WechatPaymentAttempt())
            ->setInvoiceUuid($invoice->getUuid())
            ->setStoreUuid($storeUuid)
            ->setProviderOrderNo($invoice->getOutTradeNo())
            ->setTradeType($tradeType)
            ->setExpectedAmount($amount)
            ->setCurrency(strtoupper($currency))
            ->setPayerIdentity($payerIdentity)
            ->setIdempotencyKey(trim($idempotencyKey))
            ->setStatus(WechatPaymentAttempt::STATUS_CREATED);
        $this->pinRevisions($attempt, $applicationRevision, $merchantRevision);
        $attempt->setSubMerchantId($subMerchantId)->setSubAppId($subAppId);

        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function pinRevisions(
        WechatPaymentAttempt $attempt,
        ?WechatApplicationRevision $applicationRevision,
        ?WechatMerchantRevision $merchantRevision,
    ): void {
        if ($applicationRevision !== null && $attempt->getApplicationRevision() === null) {
            $attempt->setApplicationRevision($applicationRevision);
        }
        if ($merchantRevision !== null && $attempt->getMerchantRevision() === null) {
            $attempt->setMerchantRevision($merchantRevision);
        }
    }
}
