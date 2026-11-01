<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuOperationProof;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuStoreContextResolver;

final class HuifuPaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuStoreContextResolver $storeContext,
        private readonly InvoiceServiceInterface $invoices,
        private readonly HuifuOperationProof $proof,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function start(Invoice $invoice, string $channel, array $options = []): PaymentResult
    {
        if ($invoice->getId() === null) {
            throw new \RuntimeException('The Huifu payment invoice has not been persisted.');
        }
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            /** @var Invoice|null $invoice */
            $invoice = $this->entityManager->find(Invoice::class, $invoice->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$invoice instanceof Invoice) {
                throw new \RuntimeException('The Huifu payment invoice was not found.');
            }
            if ($invoice->getStatus() !== Invoice::STATUS_PENDING) {
                throw new \RuntimeException('Only a pending invoice can start a Huifu payment.');
            }
            if ($invoice->getAmount() <= 0) {
                throw new \RuntimeException('A Huifu payment requires a positive invoice amount.');
            }
            $this->assertNoAttemptAwaitingConfirmation($invoice);
            $storeUuid = $this->storeContext->storeUuid($invoice);
            $resolved = $this->profiles->resolveForStore($storeUuid);
            $setting = $resolved['setting'];
            $channel = $channel !== '' ? $channel : $setting->getDefaultMethod();
            if (!in_array($channel, $setting->getAllowedMethods(), true)) {
                throw new \RuntimeException('The selected Huifu payment method is not enabled for this Store.');
            }
            $tradeType = (string) ($options['tradeType'] ?? '');
            if ($tradeType === '') {
                $tradeType = $channel === 'native' ? 'T_NATIVE' : 'T_JSAPI';
            }
            if (!in_array($tradeType, ['T_JSAPI', 'T_NATIVE'], true)) {
                throw new \RuntimeException('Only Huifu JSAPI and Native trade types are supported.');
            }
            if ($channel === 'quickpay' && $tradeType !== 'T_JSAPI') {
                throw new \RuntimeException('Huifu quick pay does not support the requested trade type.');
            }
            $capability = $tradeType === 'T_NATIVE' ? 'native' : ($channel === 'quickpay' ? 'quickpay' : 'jspay');
            if (!$resolved['merchant']->allows($capability)) {
                throw new \RuntimeException('The configured Huifu merchant does not enable this payment method.');
            }

            $attempt = new HuifuPaymentAttempt(
                invoice: $invoice,
                storeUuid: $storeUuid,
                providerProfileUuid: $resolved['provider']->getUuid(),
                merchantUuid: $resolved['merchant']->getUuid(),
                huifuId: $resolved['merchant']->getHuifuId(),
                credentialVersion: $resolved['provider']->getCredentialVersion(),
                reqDate: (new \DateTimeImmutable())->format('Ymd'),
                reqSeqId: self::requestSequence(),
                channel: $channel,
                tradeType: $tradeType,
                amount: $invoice->getAmount(),
                currency: $invoice->getCurrency(),
            );
            $this->entityManager->persist($attempt);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        try {
            $result = $this->invoices->pay($invoice, HuifuPaymentGateway::PAYMENT, array_merge($options, [
                'huifuAttemptUuid' => $attempt->getUuid(),
                'huifuAttemptProof' => $this->proof->payment($attempt->getUuid()),
                'gateway' => $channel,
                'tradeType' => $tradeType,
            ]));
            if ($attempt->getStatus() === HuifuPaymentAttempt::STATUS_CREATED) {
                $attempt->markClosed();
                $this->entityManager->flush();
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->markAttemptAfterFailure($attempt->getUuid(), $exception->getMessage());
            throw $exception;
        }
    }

    private function assertNoAttemptAwaitingConfirmation(Invoice $invoice): void
    {
        $pending = $this->entityManager->getRepository(HuifuPaymentAttempt::class)->findOneBy(
            [
                'invoice' => $invoice,
                'status' => [
                    HuifuPaymentAttempt::STATUS_CREATED,
                    HuifuPaymentAttempt::STATUS_SUBMITTED,
                    HuifuPaymentAttempt::STATUS_PENDING,
                    HuifuPaymentAttempt::STATUS_UNKNOWN,
                ],
            ],
            ['id' => 'DESC'],
        );
        if ($pending instanceof HuifuPaymentAttempt) {
            throw new \RuntimeException('A Huifu payment attempt for this invoice is unresolved and requires reconciliation.');
        }
    }

    private function markAttemptAfterFailure(string $attemptUuid, string $failureMessage): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }
        try {
            $attempt = $this->entityManager->getRepository(HuifuPaymentAttempt::class)->findOneBy(['uuid' => $attemptUuid]);
            if (!$attempt instanceof HuifuPaymentAttempt) {
                return;
            }
            // The Core payment transaction rolled back the gateway's status update. The in-memory
            // status still reflects how far the gateway got before the rollback.
            $gatewayStatus = $attempt->getStatus();
            $providerMessage = $attempt->getResponseMessage();
            $this->entityManager->clear();
            $attempt = $this->entityManager->getRepository(HuifuPaymentAttempt::class)->findOneBy(['uuid' => $attemptUuid]);
            if (!$attempt instanceof HuifuPaymentAttempt) {
                return;
            }
            if (in_array($gatewayStatus, [
                HuifuPaymentAttempt::STATUS_PENDING,
                HuifuPaymentAttempt::STATUS_UNKNOWN,
                HuifuPaymentAttempt::STATUS_SUCCEEDED,
                HuifuPaymentAttempt::STATUS_CLOSED,
            ], true)) {
                $attempt->markUnknown();
                $attempt->setProviderResponse(null, $providerMessage ?? 'Payment initiation failed after submission; the provider result requires reconciliation.');
            } else {
                // No provider request left the application, or Huifu explicitly rejected it.
                $message = $providerMessage !== null && trim($providerMessage) !== ''
                    ? $providerMessage
                    : (trim($failureMessage) !== '' ? trim($failureMessage) : 'Payment initiation failed before a provider result was received.');
                $attempt->markFailed($message);
                $attempt->setProviderResponse(null, $message);
            }
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Recovery must not mask the payment failure. The attempt remains queryable by its
            // request sequence for reconciliation.
        }
    }

    private static function requestSequence(): string
    {
        return 'HF'.(new \DateTimeImmutable())->format('YmdHis').strtoupper(bin2hex(random_bytes(6)));
    }
}
