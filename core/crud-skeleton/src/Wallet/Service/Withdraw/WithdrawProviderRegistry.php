<?php

declare(strict_types=1);

namespace App\Wallet\Service\Withdraw;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class WithdrawProviderRegistry
{
    /** @var array<string, WithdrawProviderInterface> */
    private array $providers = [];

    /** @param iterable<WithdrawProviderInterface> $providers */
    public function __construct(#[AutowireIterator('wallet.withdraw_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider::getName()] = $provider;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function get(string $name): WithdrawProviderInterface
    {
        return $this->providers[$name]
            ?? throw new \RuntimeException(sprintf('Withdraw provider "%s" not found.', $name));
    }

    /**
     * Route a voucher type to the first provider that supports it.
     * Returns null when no provider supports the type (whitelist rejection).
     */
    public function forVoucherType(string $voucherType): ?WithdrawProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($voucherType)) {
                return $provider;
            }
        }

        return null;
    }
}
