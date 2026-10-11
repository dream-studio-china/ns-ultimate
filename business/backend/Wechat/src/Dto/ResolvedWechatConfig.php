<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Dto;

/**
 * Fully resolved, immutable WeChat configuration for one Store operation.
 *
 * This value object holds decrypted credentials. It must only be consumed by
 * server-side client construction and must never be serialized to an API
 * response, log, or audit record.
 */
final readonly class ResolvedWechatConfig
{
    /**
     * @param list<string> $redirectHosts
     */
    public function __construct(
        public string $storeUuid,
        public string $capability,
        public string $bindingUuid,
        public ?string $applicationRevisionUuid,
        public ?string $applicationType,
        public ?string $appId,
        public ?string $appSecret,
        public ?string $officialToken = null,
        public ?string $officialAesKey = null,
        public array $redirectHosts = [],
        public ?string $merchantRevisionUuid = null,
        public ?string $merchantId = null,
        public ?string $merchantMode = null,
        public ?string $apiV3Key = null,
        public ?string $merchantPrivateKey = null,
        public ?string $merchantCertificate = null,
        public ?string $certificateSerial = null,
        public ?string $platformPublicKeyId = null,
        public ?string $platformPublicKey = null,
    ) {
    }

    public function hasApplication(): bool
    {
        return $this->appId !== null;
    }

    public function hasMerchant(): bool
    {
        return $this->merchantId !== null;
    }
}
