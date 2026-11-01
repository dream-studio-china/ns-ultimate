<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Configuration;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use NsUltimate\Business\HuifuFin\Entity\HuifuPaymentAttempt;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;

final class HuifuProfileResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuSecretCipher $cipher,
    ) {
    }

    /** @return array{setting: StoreHuifuSetting, merchant: HuifuMerchant, provider: HuifuProviderProfile, credentials: array<string, string>} */
    public function resolveForStore(string $storeUuid): array
    {
        $setting = $this->setting($storeUuid);
        if (!$setting->isEnabled()) {
            throw new \RuntimeException('Huifu payments are disabled for this Store.');
        }
        $merchant = $setting->getMerchant();
        if (!$merchant instanceof HuifuMerchant) {
            throw new \RuntimeException('Store has no Huifu merchant configured.');
        }
        if (!$merchant->isEnabled()) {
            throw new \RuntimeException('The configured Huifu merchant is disabled.');
        }
        $provider = $merchant->getProvider();
        if (!$provider->isEnabled()) {
            throw new \RuntimeException('The configured Huifu provider profile is disabled.');
        }

        return [
            'setting' => $setting,
            'merchant' => $merchant,
            'provider' => $provider,
            'credentials' => $this->credentials($provider),
        ];
    }

    /** @return array{merchant: HuifuMerchant, provider: HuifuProviderProfile, credentials: array<string, string>} */
    public function resolveForMerchant(string $merchantUuid, bool $requireEnabled = true): array
    {
        $merchant = $this->entityManager->getRepository(HuifuMerchant::class)->findOneBy(['uuid' => $merchantUuid]);
        if (!$merchant instanceof HuifuMerchant) {
            throw new \RuntimeException('Huifu merchant configuration was not found.');
        }
        if ($requireEnabled && !$merchant->isEnabled()) {
            throw new \RuntimeException('The Huifu merchant is disabled.');
        }
        $provider = $merchant->getProvider();
        if ($requireEnabled && !$provider->isEnabled()) {
            throw new \RuntimeException('The Huifu provider profile is disabled.');
        }

        return ['merchant' => $merchant, 'provider' => $provider, 'credentials' => $this->credentials($provider)];
    }

    /** @return array{provider: HuifuProviderProfile, credentials: array<string, string>} */
    public function resolveForAttempt(HuifuPaymentAttempt $attempt, bool $requireEnabled = false): array
    {
        $provider = $this->entityManager->getRepository(HuifuProviderProfile::class)
            ->findOneBy(['uuid' => $attempt->getProviderProfileUuid()]);
        if (!$provider instanceof HuifuProviderProfile) {
            throw new \RuntimeException('The Huifu provider profile used by this payment no longer exists.');
        }
        if ($requireEnabled && !$provider->isEnabled()) {
            throw new \RuntimeException('The Huifu provider profile is disabled.');
        }
        $credentials = $provider->decryptCredentialsForVersion($attempt->getCredentialVersion(), $this->cipher);
        if (($credentials['merchant_private_key'] ?? '') === '') {
            throw new \RuntimeException('The Huifu merchant private key is not configured for this credential version.');
        }
        if (($credentials['huifu_public_key'] ?? '') === '') {
            throw new \RuntimeException('The Huifu response verification public key is not configured for this credential version.');
        }

        return ['provider' => $provider, 'credentials' => $credentials];
    }

    public function setting(string $storeUuid): StoreHuifuSetting
    {
        $setting = $this->entityManager->getRepository(StoreHuifuSetting::class)->findOneBy(['storeUuid' => $storeUuid]);
        if (!$setting instanceof StoreHuifuSetting) {
            throw new \RuntimeException('Store has no Huifu configuration.');
        }

        return $setting;
    }

    /** @return array<string, string> */
    private function credentials(HuifuProviderProfile $provider): array
    {
        if (!$provider->hasCredentials()) {
            throw new \RuntimeException('The Huifu provider profile has no credentials.');
        }
        $credentials = $provider->decryptCredentials($this->cipher);
        if (($credentials['merchant_private_key'] ?? '') === '') {
            throw new \RuntimeException('The Huifu merchant private key is not configured.');
        }
        if (($credentials['huifu_public_key'] ?? '') === '') {
            throw new \RuntimeException('The Huifu response verification public key is not configured.');
        }

        return $credentials;
    }
}
