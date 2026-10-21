<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

final class PrinterProviderRegistry
{
    /** @var array<string, PrinterProviderInterface> */
    private array $providers = [];

    /** @param iterable<PrinterProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $code = $provider->code();
            if ($code === '' || isset($this->providers[$code])) {
                throw new \LogicException(sprintf('Printer provider code "%s" is empty or duplicated.', $code));
            }
            $this->providers[$code] = $provider;
        }
    }

    public function get(string $code): PrinterProviderInterface
    {
        return $this->providers[$code]
            ?? throw new \DomainException(sprintf('Printer provider "%s" is not configured.', $code));
    }

    public function has(string $code): bool { return isset($this->providers[$code]); }
}
