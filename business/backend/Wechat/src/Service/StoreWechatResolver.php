<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;

final class StoreWechatResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatSecretCipher $cipher,
    ) {
    }

    /** @return array{profile: WechatApplication, credentials: array<string, string>} */
    public function application(string $storeUuid, string $type): array
    {
        $setting = $this->setting($storeUuid);
        $profile = match ($type) {
            WechatApplication::TYPE_MINIAPP => $setting->getMiniapp(),
            WechatApplication::TYPE_OFFICIAL => $setting->getOfficial(),
            default => null,
        };
        if (!$profile instanceof WechatApplication) {
            throw new \RuntimeException(sprintf('Store has no WeChat %s application configured.', $type));
        }

        return ['profile' => $profile, 'credentials' => $profile->decryptCredentials($this->cipher)];
    }

    /** @return array{profile: WechatMerchant, credentials: array<string, string>} */
    public function merchant(string $storeUuid): array
    {
        $profile = $this->setting($storeUuid)->getMerchant();

        return ['profile' => $profile, 'credentials' => $profile->decryptCredentials($this->cipher)];
    }

    /** @return array{profile: WechatApplication, credentials: array<string, string>} */
    public function applicationByUuid(string $uuid, string $type): array
    {
        $profile = $this->entityManager->getRepository(WechatApplication::class)->findOneBy(['uuid' => $uuid]);
        if (!$profile instanceof WechatApplication || $profile->getType() !== $type) {
            throw new \RuntimeException('WeChat application configuration was not found.');
        }
        return ['profile' => $profile, 'credentials' => $profile->decryptCredentials($this->cipher)];
    }

    /** @return array{profile: WechatMerchant, credentials: array<string, string>} */
    public function merchantByUuid(string $uuid): array
    {
        $profile = $this->entityManager->getRepository(WechatMerchant::class)->findOneBy(['uuid' => $uuid]);
        if (!$profile instanceof WechatMerchant) {
            throw new \RuntimeException('WeChat merchant configuration was not found.');
        }
        return ['profile' => $profile, 'credentials' => $profile->decryptCredentials($this->cipher)];
    }

    public function setting(string $storeUuid): StoreWechatSetting
    {
        $setting = $this->entityManager->getRepository(StoreWechatSetting::class)->findOneBy(['storeUuid' => $storeUuid]);
        if (!$setting instanceof StoreWechatSetting) {
            throw new \RuntimeException('Store has no WeChat configuration.');
        }

        return $setting;
    }
}
