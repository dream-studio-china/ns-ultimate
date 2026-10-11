<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Raised when a Store WeChat operation cannot be resolved safely.
 *
 * The resolver fails closed: missing, disabled, unpublished, or mismatched
 * configuration never falls back to another Store, application, or merchant.
 */
final class WechatConfigurationException extends BadRequestHttpException
{
    public static function storeNotConfigured(string $storeUuid, string $capability): self
    {
        return new self(sprintf(
            'WeChat is not configured for store %s (capability "%s").',
            $storeUuid,
            $capability,
        ));
    }

    public static function capabilityDisabled(string $storeUuid, string $capability): self
    {
        return new self(sprintf(
            'WeChat capability "%s" is not enabled for store %s.',
            $capability,
            $storeUuid,
        ));
    }

    public static function unsupportedCapability(string $capability): self
    {
        return new self(sprintf('Unsupported WeChat capability "%s".', $capability));
    }

    public static function applicationMissing(string $storeUuid, string $capability): self
    {
        return new self(sprintf(
            'The WeChat application required for capability "%s" is not published for store %s.',
            $capability,
            $storeUuid,
        ));
    }

    public static function merchantMissing(string $storeUuid, string $capability): self
    {
        return new self(sprintf(
            'The WeChat merchant required for capability "%s" is not published for store %s.',
            $capability,
            $storeUuid,
        ));
    }

    public static function corruptCredentials(): self
    {
        return new self('The WeChat credential material could not be decrypted.');
    }
}
