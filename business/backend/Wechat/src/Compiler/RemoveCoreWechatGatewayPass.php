<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ensures exactly one deterministic gateway is registered for payment name
 * `wechat`: the project-owned Store-aware gateway replaces the core one.
 */
final class RemoveCoreWechatGatewayPass implements CompilerPassInterface
{
    private const CORE_GATEWAY = 'App\\Wechat\\Service\\Payment\\WechatPayGateway';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CORE_GATEWAY)) {
            return;
        }

        $container->getDefinition(self::CORE_GATEWAY)->clearTag('payment.gateway');
    }
}
