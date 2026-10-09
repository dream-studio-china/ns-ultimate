<?php

declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use App\Payment\Entity\Invoice;
use App\Payment\Repository\InvoiceRepository;
use App\Wallet\Entity\Voucher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Backs a wallet credit (deposit voucher) with a paid Payment invoice.
 *
 * The invoice is the funding source and MUST be paid, fully covered by the
 * deposit amount, in the same currency, and owned by the target wallet's user.
 * On successful credit the invoice `extra_data` records a `wallet_deposit`
 * hint (`status: deposited`); reversing the voucher flips it to `reverted`
 * with no change to the invoice's own lifecycle state.
 */
final class InvoiceDepositProvider implements DepositProviderInterface
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getName(): string
    {
        return Voucher::VOUCHER_TYPE_INVOICE;
    }

    public function supports(string $voucherType): bool
    {
        return $voucherType === Voucher::VOUCHER_TYPE_INVOICE;
    }

    public function assertPermitted(array $options = []): void
    {
        // The real gate is authorize(): only a paid invoice that belongs to the
        // target wallet owner may fund a credit. A user referencing their own
        // paid invoice is a valid self-service top-up source.
    }

    public function authorize(Voucher $voucher, array $options): void
    {
        $invoice = $this->invoiceRepository->findOneBy(['uuid' => $voucher->getVoucherId()]);
        if ($invoice === null) {
            throw new \RuntimeException(sprintf('Deposit invoice %s not found.', $voucher->getVoucherId()));
        }
        if ($invoice->getStatus() !== Invoice::STATUS_PAID) {
            throw new \RuntimeException(sprintf(
                'Invoice %s is not paid (status %s); it cannot fund a deposit.',
                $invoice->getUuid(),
                $invoice->getStatus(),
            ));
        }
        if ($invoice->getAmount() !== $voucher->getAmount()) {
            throw new \RuntimeException(sprintf(
                'Invoice %s amount %d does not match deposit amount %d.',
                $invoice->getUuid(),
                $invoice->getAmount(),
                $voucher->getAmount(),
            ));
        }
        if ($invoice->getCurrency() !== $voucher->getCurrency()) {
            throw new \RuntimeException(sprintf(
                'Invoice %s currency %s does not match deposit currency %s.',
                $invoice->getUuid(),
                $invoice->getCurrency(),
                $voucher->getCurrency(),
            ));
        }
        $payer = $invoice->getPayer();
        $owner = $voucher->getWallet()->getUser();
        if ($payer === null || $owner === null || $payer->getId() !== $owner->getId()) {
            throw new \RuntimeException('The invoice payer must own the target wallet.');
        }

        $invoice->appendExtraData('wallet_deposit', [
            'voucherUuid' => $voucher->getUuid(),
            'status' => 'deposited',
            'depositedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function reverse(Voucher $voucher, string $reason, array $options = []): void
    {
        $invoice = $this->invoiceRepository->findOneBy(['uuid' => $voucher->getVoucherId()]);
        if ($invoice === null) {
            return;
        }

        $invoice->appendExtraData('wallet_deposit', [
            'voucherUuid' => $voucher->getUuid(),
            'status' => 'reverted',
            'revertedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        // DepositService invokes this hook after its own transaction; persist the
        // hint explicitly so the invoice read-model reflects the reversal.
        $this->entityManager->flush();
    }
}