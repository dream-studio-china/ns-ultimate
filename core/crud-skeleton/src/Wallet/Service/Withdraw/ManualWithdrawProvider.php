<?php

declare(strict_types=1);

namespace App\Wallet\Service\Withdraw;

use App\Wallet\Entity\Voucher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ManualWithdrawProvider implements WithdrawProviderInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getName(): string
    {
        return Voucher::VOUCHER_TYPE_MANUAL;
    }

    public function supports(string $voucherType): bool
    {
        return $voucherType === Voucher::VOUCHER_TYPE_MANUAL;
    }

    public function assertPermitted(array $options = []): void
    {
        // No active security context (CLI/queue/system) is a trusted caller.
        if ($this->security->getToken() === null) {
            return;
        }

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Manual voucher type is admin-only.');
        }
    }

    public function authorize(Voucher $voucher, array $options): void
    {
        // Manual withdrawals are authorized by the permission check + the admin action.
    }

    public function reverse(Voucher $voucher, string $reason, array $options = []): void
    {
        // Manual withdrawal reversal is fully handled by WithdrawService.
    }
}
