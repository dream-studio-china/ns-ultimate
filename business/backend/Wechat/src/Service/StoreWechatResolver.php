<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Dto\ResolvedWechatConfig;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Entity\WechatPaymentAttempt;
use NsUltimate\Business\Wechat\Exception\WechatConfigurationException;

/**
 * Resolves a Store and capability to an immutable, decrypted configuration.
 *
 * Resolution reads the authoritative database state on every call so a saved
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

        if (!$setting->supports($capability)) {
            throw WechatConfigurationException::capabilityDisabled($storeUuid, $capability);
        }

        $applicationType = StoreWechatSetting::CAPABILITY_APPLICATION_TYPE[$capability] ?? null;
        if ($applicationType === null) {
            throw WechatConfigurationException::unsupportedCapability($capability);
        }

        $applicationRevision = $setting->applicationRevisionFor($applicationType);
        if (!$applicationRevision instanceof WechatApplicationRevision
            || !$applicationRevision->isPublished()
            || $applicationRevision->isRevoked()) {
            throw WechatConfigurationException::applicationMissing($storeUuid, $capability);
        }

        $application = $applicationRevision->getApplication();
        if ($application === null || !$application->isEnabled() || $application->getType() !== $applicationType) {
            throw WechatConfigurationException::applicationMissing($storeUuid, $capability);
        }

        $merchantRevision = null;
        if (in_array($capability, StoreWechatSetting::PAYMENT_CAPABILITIES, true)) {
            $merchantRevision = $setting->getMerchantRevision();
            if (!$merchantRevision instanceof WechatMerchantRevision
                || !$merchantRevision->isPublished()
                || $merchantRevision->isRevoked()) {
                throw WechatConfigurationException::merchantMissing($storeUuid, $capability);
            }
            if (!$merchantRevision->getMerchant()?->isEnabled()) {
                throw WechatConfigurationException::merchantMissing($storeUuid, $capability);
            }
        }

        return new ResolvedWechatConfig(
            storeUuid: $storeUuid,
            capability: $capability,
            bindingUuid: $setting->getUuid(),
            applicationRevisionUuid: $applicationRevision->getUuid(),
            applicationType: $application->getType(),
            appId: $application->getAppId(),
            appSecret: $this->decryptRequired($applicationRevision->encryptedSecret()),
            officialToken: $this->decryptOptional($applicationRevision->encryptedOfficialToken()),
            officialAesKey: $this->decryptOptional($applicationRevision->encryptedOfficialAesKey()),
            redirectHosts: $applicationRevision->getRedirectHosts(),
            merchantRevisionUuid: $merchantRevision?->getUuid(),
            merchantId: $merchantRevision?->getMerchant()?->getMerchantId(),
            merchantMode: $merchantRevision?->getMode(),
            apiV3Key: $merchantRevision === null ? null : $this->decryptRequired($merchantRevision->encryptedApiV3Key()),
            merchantPrivateKey: $merchantRevision === null ? null : $this->decryptRequired($merchantRevision->encryptedPrivateKey()),
            merchantCertificate: $merchantRevision === null ? null : $this->decryptRequired($merchantRevision->encryptedCertificate()),
            certificateSerial: $merchantRevision?->getCertificateSerial(),
            platformPublicKeyId: $merchantRevision?->getPlatformPublicKeyId(),
            platformPublicKey: $merchantRevision === null
                ? null
                : $this->decryptOptional($merchantRevision->encryptedPlatformPublicKey()),
            subMerchantId: $setting->getSubMerchantId(),
            subAppId: $setting->getSubAppId(),
        );
    }

    /**
     * Rebuild the configuration pinned by an existing payment attempt.
     *
     * Historical callbacks and refunds must use the attempt's original
     * application/merchant revisions, not the Store's current binding.
     */
    public function resolveForAttempt(WechatPaymentAttempt $attempt): ResolvedWechatConfig
    {
        $applicationRevision = $attempt->getApplicationRevision();
        $merchantRevision = $attempt->getMerchantRevision();
        if (!$applicationRevision instanceof WechatApplicationRevision || !$merchantRevision instanceof WechatMerchantRevision) {
            throw WechatConfigurationException::corruptCredentials();
        }
        if ($applicationRevision->isRevoked() || $merchantRevision->isRevoked()) {
            throw WechatConfigurationException::corruptCredentials();
        }

        $application = $applicationRevision->getApplication();
        $merchant = $merchantRevision->getMerchant();
        if ($application === null || $merchant === null) {
            throw WechatConfigurationException::corruptCredentials();
        }

        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['storeUuid' => $attempt->getStoreUuid()]);

        return new ResolvedWechatConfig(
            storeUuid: $attempt->getStoreUuid(),
            capability: $attempt->getTradeType(),
            bindingUuid: $attempt->getUuid(),
            applicationRevisionUuid: $applicationRevision->getUuid(),
            applicationType: $application->getType(),
            appId: $application->getAppId(),
            appSecret: $this->decryptRequired($applicationRevision->encryptedSecret()),
            officialToken: $this->decryptOptional($applicationRevision->encryptedOfficialToken()),
            officialAesKey: $this->decryptOptional($applicationRevision->encryptedOfficialAesKey()),
            redirectHosts: $applicationRevision->getRedirectHosts(),
            merchantRevisionUuid: $merchantRevision->getUuid(),
            merchantId: $merchant->getMerchantId(),
            merchantMode: $merchantRevision->getMode(),
            apiV3Key: $this->decryptRequired($merchantRevision->encryptedApiV3Key()),
            merchantPrivateKey: $this->decryptRequired($merchantRevision->encryptedPrivateKey()),
            merchantCertificate: $this->decryptRequired($merchantRevision->encryptedCertificate()),
            certificateSerial: $merchantRevision->getCertificateSerial(),
            platformPublicKeyId: $merchantRevision->getPlatformPublicKeyId(),
            platformPublicKey: $this->decryptOptional($merchantRevision->encryptedPlatformPublicKey()),
            subMerchantId: $setting?->getSubMerchantId(),
            subAppId: $setting?->getSubAppId(),
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
        } catch (\RuntimeException) {
            throw WechatConfigurationException::corruptCredentials();
        }
    }
}
