<?php
declare(strict_types=1);

namespace App\Wallet\Integration\Settlement;

use App\Settlement\Contract\ConfirmedAllocation;
use App\Settlement\Contract\PostedAllocation;
use App\Settlement\Contract\ReversalRequest;
use App\Settlement\Contract\VoucherPostingReceipt;
use App\Settlement\Exception\SettlementVoucherException;
use App\Settlement\Port\SettlementVoucherPort;
use App\Wallet\Entity\Voucher;
use App\Wallet\Exception\InsufficientFundsException;
use App\Wallet\Repository\VoucherRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Deposit\DepositServiceInterface;

final class WalletSettlementVoucherPort implements SettlementVoucherPort
{
    public function __construct(private readonly DepositServiceInterface $deposits, private readonly VoucherRepository $vouchers, private readonly WalletRepository $wallets) {}

    public function post(ConfirmedAllocation $allocation): VoucherPostingReceipt
    {
        try {
            $walletId = $this->walletId($allocation->recipient->type, $allocation->recipient->id);
            $wallet = $this->wallets->findById($walletId);
            if ($wallet === null || $wallet->getCurrency() !== strtoupper($allocation->currency)) throw new \InvalidArgumentException('Settlement recipient wallet is invalid.');
            $amount = $this->minorInt($allocation->postingMinor());
            $voucher = $this->deposits->deposit('settlement', $allocation->allocationUuid, $walletId, $amount, $allocation->currency, $allocation->postingIdempotencyKey, 'settlement', $allocation->reasonCode);
            $this->assertVoucher($voucher, $walletId, $allocation->allocationUuid, $amount, $allocation->currency, Voucher::STATUS_APPLIED);
            return new VoucherPostingReceipt($voucher->getUuid(), $allocation->postingIdempotencyKey, $voucher->getAppliedAt() ?? new \DateTimeImmutable(), 'applied');
        } catch (SettlementVoucherException $exception) { throw $exception; }
        catch (\Throwable $exception) { throw new SettlementVoucherException($exception->getMessage(), false, 'wallet_rejected'); }
    }

    public function reverse(PostedAllocation $allocation, ReversalRequest $request): VoucherPostingReceipt
    {
        try {
            $voucher = $this->vouchers->findByUuid($allocation->externalReference);
            if ($voucher === null) throw new \InvalidArgumentException('Original settlement voucher not found.');
            $this->assertVoucher($voucher, $this->walletId($allocation->recipient->type, $allocation->recipient->id), $allocation->allocationUuid, $this->minorInt($allocation->postingAmount), $allocation->currency, null);
            if ($voucher->getStatus() === Voucher::STATUS_REVERSED) return new VoucherPostingReceipt('wallet-voucher-reversal:' . $voucher->getUuid(), $allocation->reversalIdempotencyKey, $voucher->getReversedAt() ?? new \DateTimeImmutable(), 'reversed');
            if ($voucher->getStatus() !== Voucher::STATUS_APPLIED) throw new \InvalidArgumentException('Original settlement voucher is not reversible.');
            $this->deposits->reverse($voucher->getUuid(), $request->reasonCode . ': ' . $request->reasonDetail);
            return new VoucherPostingReceipt('wallet-voucher-reversal:' . $voucher->getUuid(), $allocation->reversalIdempotencyKey, new \DateTimeImmutable(), 'reversed');
        } catch (InsufficientFundsException $exception) { throw new SettlementVoucherException($exception->getMessage(), false, 'insufficient_funds'); }
        catch (SettlementVoucherException $exception) { throw $exception; }
        catch (\Throwable $exception) { throw new SettlementVoucherException($exception->getMessage(), false, 'wallet_reversal_rejected'); }
    }

    private function walletId(string $type, string $id): int
    {
        if ($type !== 'wallet' || !ctype_digit($id) || (int) $id < 1) throw new SettlementVoucherException('Settlement recipients must reference a wallet id.', false, 'recipient_unsupported');
        return (int) $id;
    }
    private function minorInt(string $amount): int
    {
        if (!ctype_digit($amount) || strlen($amount) > strlen((string) PHP_INT_MAX) || (strlen($amount) === strlen((string) PHP_INT_MAX) && $amount > (string) PHP_INT_MAX) || $amount === '0') throw new SettlementVoucherException('Settlement posting amount is invalid for Wallet.', false, 'amount_invalid');
        return (int) $amount;
    }
    private function assertVoucher(Voucher $voucher, int $walletId, string $allocationUuid, int $amount, string $currency, ?string $status): void
    {
        if ($voucher->getWallet()->getId() !== $walletId || $voucher->getVoucherType() !== 'settlement' || $voucher->getVoucherId() !== $allocationUuid || $voucher->getReferenceId() !== 'settlement-credit:' . $allocationUuid || $voucher->getDirection() !== Voucher::DIRECTION_CREDIT || $voucher->getAmount() !== $amount || $voucher->getCurrency() !== strtoupper($currency) || ($status !== null && $voucher->getStatus() !== $status)) throw new SettlementVoucherException('Settlement voucher integrity conflict.', false, 'integrity_conflict');
    }
}
