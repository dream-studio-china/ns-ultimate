<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\Exception\PaymentGatewayNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    /** @param iterable<PaymentGatewayInterface> $gateways */
    public function __construct(#[AutowireIterator('payment.gateway')] iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway::getName()] = $gateway;
        }
    }

    public function get(string $name): PaymentGatewayInterface
    {
        return $this->gateways[$name] ?? throw new PaymentGatewayNotFoundException($name);
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[$name]);
    }

    /** @return string[] */
    public function names(): array
    {
        $names = array_keys($this->gateways);
        sort($names);
        return $names;
    }
}
