<?php

declare(strict_types=1);

namespace App\Wallet\Service\Deposit;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class DepositProviderRegistry
{
    /** @var array<string, DepositProviderInterface> */
    private array $providers = [];

    /** @param iterable<DepositProviderInterface> $providers */
    public function __construct(#[AutowireIterator('wallet.deposit_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider::getName()] = $provider;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function get(string $name): DepositProviderInterface
    {
        return $this->providers[$name]
            ?? throw new \RuntimeException(sprintf('Deposit provider "%s" not found.', $name));
    }

    /**
     * Route a voucher type to the first provider that supports it.
     * Returns null when no provider supports the type (whitelist rejection).
     */
    public function forVoucherType(string $voucherType): ?DepositProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($voucherType)) {
                return $provider;
            }
        }

        return null;
    }
}
