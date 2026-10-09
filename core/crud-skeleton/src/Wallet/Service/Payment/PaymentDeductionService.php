<?php

declare(strict_types=1);

namespace App\Wallet\Service\Payment;

use App\Core\Service\BaseService;
use App\Payment\Entity\Invoice;
use App\Wallet\DTO\PaymentDeductionRequest;
use App\Wallet\Entity\PaymentDeduction;
use App\Wallet\Repository\PaymentDeductionRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Wallet\Entity\PaymentDeduction> */
final class PaymentDeductionService extends BaseService implements PaymentDeductionServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly PaymentDeductionRepository $deductionRepository,
        private readonly WalletRepository $walletRepository,
        private readonly TransferServiceInterface $transferService,
        #[Autowire('%payment.system_wallet_id%')]
        private readonly ?int $systemWalletId = null,
    ) {
        parent::__construct($container, PaymentDeduction::class);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createRequestFromOptions(Invoice $invoice, array $options): ?PaymentDeductionRequest
    {
        if (isset($options['walletAmount'])) {
            $amount = (int) $options['walletAmount'];
            if ($amount <= 0) {
                return null;
            }

            return new PaymentDeductionRequest(
                PaymentDeduction::TYPE_WALLET_BALANCE,
                $amount,
                (string) ($options['currency'] ?? $invoice->getCurrency()),
                $options,
            );
        }

        $deduction = $options['deduction'] ?? null;
        if (!is_array($deduction)) {
            return null;
        }

        return new PaymentDeductionRequest(
            (string) ($deduction['type'] ?? PaymentDeduction::TYPE_WALLET_BALANCE),
            (int) ($deduction['amount'] ?? 0),
            (string) ($deduction['currency'] ?? $invoice->getCurrency()),
            array_merge($options, $deduction['options'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function applyFromOptions(Invoice $invoice, array $options): ?PaymentDeduction
    {
        $request = $this->createRequestFromOptions($invoice, $options);
        if ($request === null) {
            return null;
        }

        return $this->apply($invoice, $request->amount, $request->currency, $request->options, $request->type);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function apply(Invoice $invoice, int $amount, string $currency, array $options = [], string $type = PaymentDeduction::TYPE_WALLET_BALANCE): PaymentDeduction
    {
        $this->validate($invoice, $amount, $currency, $type);

        $existing = $this->deductionRepository->findWalletBalanceByInvoice($invoice);
        if ($existing instanceof PaymentDeduction) {
            if ($existing->getStatus() === PaymentDeduction::STATUS_APPLIED) {
                return $existing;
            }
            throw new \RuntimeException(sprintf('Invoice wallet deduction already exists with status "%s".', $existing->getStatus()));
        }

        $payer = $invoice->getPayer();
        if ($payer === null || $payer->getId() === null) {
            throw new \RuntimeException('Invoice has no payer for wallet deduction.');
        }

        $systemWalletId = (int) ($options['systemWalletId'] ?? $this->systemWalletId ?? 0);
        if ($systemWalletId <= 0) {
            throw new \InvalidArgumentException('systemWalletId is required for wallet deduction.');
        }

        $wallet = $this->walletRepository->findByUserAndCurrency($payer->getId(), $currency);
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for payer.', strtoupper($currency)));
        }

        $referenceId = $options['deductionReferenceId'] ?? ('deduction-balance-' . $invoice->getUuid());
        $deduction = new PaymentDeduction($invoice, $wallet, $systemWalletId, $amount, $currency, $referenceId);
        $this->em->persist($deduction);

        try {
            $result = $this->transferService->transfer(
                $wallet->getId(),
                $systemWalletId,
                $amount,
                $referenceId,
                $invoice->getSubject() ?? ('Deduction for invoice ' . $invoice->getOutTradeNo()),
            );

            $deduction->markApplied($result->transaction->getUuid(), [
                'fromWalletId' => $wallet->getId(),
                'toWalletId' => $systemWalletId,
            ]);
            $this->em->flush();

            return $deduction;
        } catch (\Throwable $e) {
            $deduction->markFailed($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    public function release(Invoice $invoice, string $reason): ?PaymentDeduction
    {
        $deduction = $this->deductionRepository->findAppliedByInvoice($invoice);
        if (!$deduction instanceof PaymentDeduction) {
            return null;
        }

        return $this->reverse($deduction, 'deduction-release-' . $invoice->getUuid(), $reason, false);
    }

    public function refund(Invoice $invoice, string $reason): ?PaymentDeduction
    {
        $deduction = $this->deductionRepository->findAppliedByInvoice($invoice);
        if (!$deduction instanceof PaymentDeduction) {
            return null;
        }

        return $this->reverse($deduction, 'deduction-refund-' . $invoice->getUuid(), $reason, true);
    }

    public function sumAppliedAmount(Invoice $invoice): int
    {
        $sum = 0;
        foreach ($this->deductionRepository->findAppliedDeductions($invoice) as $deduction) {
            $sum += $deduction->getAmount();
        }

        return $sum;
    }

    public function findApplied(Invoice $invoice): ?PaymentDeduction
    {
        return $this->deductionRepository->findAppliedByInvoice($invoice);
    }

    public function hasApplied(Invoice $invoice): bool
    {
        return $this->findApplied($invoice) instanceof PaymentDeduction;
    }

    private function validate(Invoice $invoice, int $amount, string $currency, string $type): void
    {
        if ($type !== PaymentDeduction::TYPE_WALLET_BALANCE) {
            throw new \InvalidArgumentException(sprintf('Unsupported deduction type: %s', $type));
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deduction amount must be positive.');
        }
        if ($amount > $invoice->getAmount()) {
            throw new \InvalidArgumentException('Deduction amount cannot exceed invoice amount.');
        }
        if (strtoupper($currency) !== $invoice->getCurrency()) {
            throw new \InvalidArgumentException('Deduction currency must match invoice currency.');
        }
    }

    private function reverse(PaymentDeduction $deduction, string $referenceId, string $reason, bool $refund): PaymentDeduction
    {
        $walletId = $deduction->getWallet()->getId();
        \assert($walletId !== null);

        $result = $this->transferService->transfer(
            $deduction->getSystemWalletId(),
            $walletId,
            $deduction->getAmount(),
            $referenceId,
            $reason,
        );

        if ($refund) {
            $deduction->markRefunded($result->transaction->getUuid(), $reason);
        } else {
            $deduction->markReleased($result->transaction->getUuid(), $reason);
        }
        $this->em->flush();

        return $deduction;
    }
}
