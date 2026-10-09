<?php

declare(strict_types=1);

namespace App\Wallet\Service;

use App\Wallet\Entity\Voucher;
use App\Wallet\Repository\VoucherRepository;

/**
 * Reconciliation surface for the boundary ledger. The voucher record is the
 * only path funds enter/leave the system, so it is the place to reconcile
 * against external statements (bank/wechat). Full statement integration is
 * deferred; this service lists applied vouchers and performs a basic match
 * against external lines by (referenceId, amount, direction), flagging
 * unmatched vouchers as needing reconciliation.
 */
final class ReconciliationService implements ReconciliationServiceInterface
{
    public function __construct(
        private readonly VoucherRepository $voucherRepository,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listBoundaryVouchers(
        string $currency,
        ?string $fundSource = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array {
        if ($currency === '') {
            throw new \InvalidArgumentException('Currency is required');
        }

        return array_values(array_map(
            self::serializeVoucher(...),
            $this->voucherRepository->findForReconciliation($currency, $fundSource, $from, $to),
        ));
    }

    /**
     * Basic reconciliation stub: match applied external vouchers against the
     * provided external statement lines by (referenceId, amount, direction).
     * Vouchers with no matching external line are flagged as needing
     * reconciliation. Real statement parsing is deferred.
     *
     * @param list<array<string, mixed>> $externalLines
     * @return array<string, mixed>
     */
    public function reconcileAgainstExternal(string $currency, array $externalLines): array
    {
        $vouchers = $this->listBoundaryVouchers($currency, Voucher::FUND_SOURCE_EXTERNAL);

        $matched = [];
        $unmatched = [];
        foreach ($vouchers as $voucher) {
            $line = $this->findMatchingExternalLine($externalLines, $voucher);
            if ($line !== null) {
                $matched[] = ['voucher' => $voucher, 'external' => $line];
            } else {
                $unmatched[] = $voucher;
            }
        }

        return [
            'currency' => strtoupper($currency),
            'status' => $unmatched === [] ? 'ok' : 'needs_reconcile',
            'note' => $unmatched === []
                ? 'All external vouchers matched.'
                : 'Basic match only; vouchers without an external line need reconciliation.',
            'matched' => $matched,
            'unmatchedVouchers' => $unmatched,
            'externalLines' => $externalLines,
        ];
    }

    /**
     * @param list<array<string, mixed>> $externalLines
     * @param array<string, mixed> $voucher
     * @return array<string, mixed>|null
     */
    private function findMatchingExternalLine(array $externalLines, array $voucher): ?array
    {
        foreach ($externalLines as $line) {
            $sameReference = ($line['referenceId'] ?? null) === $voucher['referenceId'];
            $sameAmount = (int) ($line['amount'] ?? 0) === $voucher['amount'];
            $sameDirection = ($line['direction'] ?? null) === $voucher['direction'];
            if ($sameReference && $sameAmount && $sameDirection) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeVoucher(Voucher $voucher): array
    {
        return [
            'uuid' => $voucher->getUuid(),
            'direction' => $voucher->getDirection(),
            'fundSource' => $voucher->getFundSource(),
            'voucherType' => $voucher->getVoucherType(),
            'voucherId' => $voucher->getVoucherId(),
            'walletId' => $voucher->getWallet()->getId(),
            'amount' => $voucher->getAmount(),
            'currency' => $voucher->getCurrency(),
            'status' => $voucher->getStatus(),
            'referenceId' => $voucher->getReferenceId(),
            'createdBy' => $voucher->getCreatedBy(),
            'walletTransactionId' => $voucher->getTransactionId(),
            'reversalTransactionId' => $voucher->getReversalTransactionId(),
            'createdAt' => $voucher->getCreatedAt()->format(DATE_ATOM),
            'appliedAt' => $voucher->getAppliedAt()?->format(DATE_ATOM),
            'reversedAt' => $voucher->getReversedAt()?->format(DATE_ATOM),
        ];
    }
}
