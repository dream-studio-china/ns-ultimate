<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\StoreWechatSettingRevision;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Exception\WechatConfigurationException;

/**
 * Resolves a Store and capability to an immutable, decrypted configuration.
 *
 * Resolution reads the authoritative database state on every call so a published
 * binding takes effect for newly started operations without a process restart.
 * The caller must supply the trusted Store context; this service never selects a
 * Store from request data on its own.
 */
final class StoreWechatResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretCipher $cipher,
    ) {
    }

    public function resolve(string $storeUuid, string $capability): ResolvedWechatConfig
    {
        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['storeUuid' => $storeUuid]);

        if (!$setting instanceof StoreWechatSetting || !$setting->isEnabled()) {
            throw WechatConfigurationException::storeNotConfigured($storeUuid, $capability);
        }

        $binding = $this->entityManager
            ->getRepository(StoreWechatSettingRevision::class)
            ->findOneBy(['setting' => $setting, 'state' => StoreWechatSettingRevision::STATE_PUBLISHED]);
        if (!$binding instanceof StoreWechatSettingRevision) {
            throw WechatConfigurationException::storeNotConfigured($storeUuid, $capability);
        }

        if (!$binding->supports($capability)) {
            throw WechatConfigurationException::capabilityDisabled($storeUuid, $capability);
        }

        $applicationType = StoreWechatSettingRevision::CAPABILITY_APPLICATION_TYPE[$capability] ?? null;
        if ($applicationType === null) {
            throw WechatConfigurationException::unsupportedCapability($capability);
        }

        $applicationRevision = $binding->applicationRevisionFor($applicationType);
        if (!$applicationRevision instanceof WechatApplicationRevision || !$applicationRevision->isPublished()) {
            throw WechatConfigurationException::applicationMissing($storeUuid, $capability);
        }

        $application = $applicationRevision->getApplication();
        if (!$application->isEnabled() || $application->getType() !== $applicationType) {
            throw WechatConfigurationException::applicationMissing($storeUuid, $capability);
        }

        $merchantRevision = null;
        if (in_array($capability, StoreWechatSettingRevision::PAYMENT_CAPABILITIES, true)) {
            $merchantRevision = $binding->getMerchantRevision();
            if (!$merchantRevision instanceof WechatMerchantRevision || !$merchantRevision->isPublished()) {
                throw WechatConfigurationException::merchantMissing($storeUuid, $capability);
            }
            if (!$merchantRevision->getMerchant()->isEnabled()) {
                throw WechatConfigurationException::merchantMissing($storeUuid, $capability);
            }
        }

        return new ResolvedWechatConfig(
            storeUuid: $storeUuid,
            capability: $capability,
            bindingRevisionUuid: $binding->getUuid(),
            applicationRevisionUuid: $applicationRevision->getUuid(),
            applicationType: $application->getType(),
            appId: $application->getAppId(),
            appSecret: $this->decryptRequired($applicationRevision->encryptedSecret()),
            officialToken: $this->decryptOptional($applicationRevision->encryptedOfficialToken()),
            officialAesKey: $this->decryptOptional($applicationRevision->encryptedOfficialAesKey()),
            redirectHosts: $applicationRevision->getRedirectHosts(),
            merchantRevisionUuid: $merchantRevision?->getUuid(),
            merchantId: $merchantRevision?->getMerchant()->getMerchantId(),
            merchantMode: $merchantRevision?->getMode(),
            apiV3Key: $merchantRevision === null ? null : $this->decryptRequired($merchantRevision->encryptedApiV3Key()),
            merchantPrivateKey: $merchantRevision === null ? null : $this->decryptRequired($merchantRevision->encryptedPrivateKey()),
            merchantCertificate: $merchantRevision === null ? null : $this->decryptRequired($merchantRevision->encryptedCertificate()),
            certificateSerial: $merchantRevision?->getCertificateSerial(),
            platformPublicKeyId: $merchantRevision?->getPlatformPublicKeyId(),
            platformPublicKey: $merchantRevision === null
                ? null
                : $this->decryptOptional($merchantRevision->encryptedPlatformPublicKey()),
        );
    }

    public function isConfigured(string $storeUuid, string $capability): bool
    {
        try {
            $this->resolve($storeUuid, $capability);
            return true;
        } catch (WechatConfigurationException) {
            return false;
        }
    }

    private function decryptRequired(?string $payload): string
    {
        if ($payload === null) {
            throw WechatConfigurationException::corruptCredentials();
        }

        return $this->decryptOptional($payload) ?? throw WechatConfigurationException::corruptCredentials();
    }

    private function decryptOptional(?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        try {
            return $this->cipher->decrypt($payload);
        } catch (\RuntimeException $e) {
            throw WechatConfigurationException::corruptCredentials();
        }
    }
}
