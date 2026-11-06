<?php

declare(strict_types=1);

namespace NsUltimate\Business\Payment\Service;

use NsUltimate\Business\Payment\Contract\PaymentCloseGatewayInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PaymentCloseGatewayRegistry
{
    /** @var array<string, PaymentCloseGatewayInterface> */
    private array $gateways = [];

    /** @param iterable<PaymentCloseGatewayInterface> $gateways */
    public function __construct(#[AutowireIterator('ns.payment.close_gateway')] iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            $name = $gateway::payment();
            if (isset($this->gateways[$name])) throw new \LogicException('Duplicate payment close capability: '.$name);
            $this->gateways[$name] = $gateway;
        }
    }

    public function get(string $payment): PaymentCloseGatewayInterface
    {
        return $this->gateways[$payment] ?? throw new HttpException(409, 'payment_close_not_supported');
    }
}
