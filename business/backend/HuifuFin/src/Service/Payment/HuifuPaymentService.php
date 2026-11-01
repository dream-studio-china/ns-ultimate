<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Payment;

use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuStoreContextResolver;

final class HuifuPaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuStoreContextResolver $storeContext,
        private readonly InvoiceServiceInterface $invoices,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function start(Invoice $invoice, string $channel, array $options = []): PaymentResult
    {
        if ($invoice->getStatus() !== Invoice::STATUS_PENDING) {
            throw new \RuntimeException('Only a pending invoice can start a Huifu payment.');
        }
        if ($invoice->getAmount() <= 0) {
            throw new \RuntimeException('A Huifu payment requires a positive invoice amount.');
        }
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

        try {
            $result = $this->invoices->pay($invoice, HuifuPaymentGateway::PAYMENT, array_merge($options, [
                'huifuAttemptUuid' => $attempt->getUuid(),
                'gateway' => $channel,
                'tradeType' => $tradeType,
            ]));
            if ($attempt->getStatus() === HuifuPaymentAttempt::STATUS_CREATED) {
                $attempt->markClosed();
                $this->entityManager->flush();
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->markAttemptAfterFailure($attempt->getUuid());
            throw $exception;
        }
    }

    private function markAttemptAfterFailure(string $attemptUuid): void
    {
        $attempt = $this->entityManager->getRepository(HuifuPaymentAttempt::class)->findOneBy(['uuid' => $attemptUuid]);
        if (!$attempt instanceof HuifuPaymentAttempt) {
            return;
        }
        if (in_array($attempt->getStatus(), [HuifuPaymentAttempt::STATUS_CREATED, HuifuPaymentAttempt::STATUS_SUBMITTED], true)) {
            $attempt->markUnknown();
            $attempt->setProviderResponse(null, 'Payment initiation failed; the provider result requires reconciliation.');
            $this->entityManager->flush();
        }
    }

    private static function requestSequence(): string
    {
        return 'HF'.(new \DateTimeImmutable())->format('YmdHis').strtoupper(bin2hex(random_bytes(6)));
    }
}
