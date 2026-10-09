<?php

declare(strict_types=1);

namespace App\Wallet\Service\Withdraw;

use App\Wallet\Entity\Voucher;

interface WithdrawProviderInterface
{
    public static function getName(): string;

    public function supports(string $voucherType): bool;

    /**
     * Assert the current caller is permitted to use this voucher type. Each
     * provider owns its permission rule (roles, context, etc.) and MUST throw
     * \Symfony\Component\Security\Core\Exception\AccessDeniedException when the
     * caller is not permitted. When there is no active security context
     * (CLI/queue/system invocation) the call is trusted and MUST be allowed.
     *
     * @param array<string, mixed> $options
     */
    public function assertPermitted(array $options = []): void;

    /**
     * Verify the destination is ready to receive the withdrawn funds (e.g. an
     * external payout account). Must throw when the source is not in a state
     * that allows the withdrawal.
     *
     * @param array<string, mixed> $options
     */
    public function authorize(Voucher $voucher, array $options): void;

    /**
     * Hook for destination-side handling when a withdrawal voucher is reversed.
     *
     * @param array<string, mixed> $options
     */
    public function reverse(Voucher $voucher, string $reason, array $options = []): void;
}
