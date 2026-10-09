<?php

declare(strict_types=1);

namespace App\Wallet\Service\Payment;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\PaymentGatewayInterface;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WalletGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly WalletRepository $walletRepository,
        private readonly TransferServiceInterface $transferService,
        #[Autowire('%payment.system_wallet_id%')]
        private readonly ?int $systemWalletId = null,
    ) {}

    public static function getName(): string
    {
        return Invoice::PAYMENT_WALLET;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult
    {
        $systemWalletId = (int) ($options['systemWalletId'] ?? $this->systemWalletId ?? 0);
        if ($systemWalletId <= 0) {
            throw new \InvalidArgumentException('systemWalletId is required for wallet payment.');
        }
        $payer = $invoice->getPayer();
        if ($payer === null || $payer->getId() === null) {
            throw new \RuntimeException('Invoice has no payer for wallet payment.');
        }
        $wallet = $this->walletRepository->findByUserAndCurrency($payer->getId(), $invoice->getCurrency());
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for payer.', $invoice->getCurrency()));
        }
        $result = $this->transferService->transfer(
            $wallet->getId(),
            $systemWalletId,
            $amount,
            'invoice-pay-' . $invoice->getOutTradeNo(),
            $invoice->getSubject() ?? ('Payment for invoice ' . $invoice->getOutTradeNo()),
        );

        return new PaymentResult(
            invoice: $invoice,
            status: Invoice::STATUS_PAID,
            payload: [
                'transactionId' => $result->transaction->getUuid(),
                'fromWalletId' => $wallet->getId(),
                'toWalletId' => $systemWalletId,
            ],
            message: 'Wallet payment completed',
        );
    }

    public function notify(Request $request): PaymentNotifyResult
    {
        throw new PaymentVerificationException('Wallet gateway does not accept external notify callbacks.');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult
    {
        $systemWalletId = (int) ($options['systemWalletId'] ?? $this->systemWalletId ?? 0);
        if ($systemWalletId <= 0) {
            throw new \InvalidArgumentException('systemWalletId is required for wallet refund.');
        }
        $payer = $invoice->getPayer();
        if ($payer === null || $payer->getId() === null) {
            throw new \RuntimeException('Invoice has no payer for wallet refund.');
        }
        $wallet = $this->walletRepository->findByUserAndCurrency($payer->getId(), $invoice->getCurrency());
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for payer.', $invoice->getCurrency()));
        }

        $transfer = $this->transferService->transfer(
            $systemWalletId,
            $wallet->getId(),
            $amount,
            'invoice-refund-' . $invoice->getOutTradeNo() . '-' . ($invoice->getRefundedAmount() + $amount),
            $reason,
        );

        return new PaymentRefundResult(
            invoice: $invoice,
            amount: $amount,
            status: $amount >= ($paidAmount - $invoice->getRefundedAmount()) ? Invoice::STATUS_REFUNDED : Invoice::STATUS_PARTIAL_REFUNDED,
            refundId: $transfer->transaction->getUuid(),
            rawData: ['reason' => $reason, 'transactionId' => $transfer->transaction->getUuid()],
        );
    }

    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response
    {
        return new Response($result->responseBody, 200, ['Content-Type' => 'text/plain']);
    }
}
