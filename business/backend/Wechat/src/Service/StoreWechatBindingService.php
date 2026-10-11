<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\StoreWechatSettingRevision;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use NsUltimate\Business\Wechat\Exception\WechatValidationException;

/**
 * Manages the per-Store binding of published application and merchant revisions.
 */
final class StoreWechatBindingService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function settingForStore(string $storeUuid): StoreWechatSetting
    {
        $setting = $this->entityManager
            ->getRepository(StoreWechatSetting::class)
            ->findOneBy(['storeUuid' => $storeUuid]);

        if (!$setting instanceof StoreWechatSetting) {
            $setting = new StoreWechatSetting($storeUuid);
            $this->entityManager->persist($setting);
            $this->entityManager->flush();
        }

        return $setting;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createRevision(StoreWechatSetting $setting, array $input): StoreWechatSettingRevision
    {
        $miniapp = $this->publishedApplicationRevision($input['miniappRevisionUuid'] ?? null, WechatApplication::TYPE_MINIAPP);
        $official = $this->publishedApplicationRevision($input['officialRevisionUuid'] ?? null, WechatApplication::TYPE_OFFICIAL);
        $merchant = $this->publishedMerchantRevision($input['merchantRevisionUuid'] ?? null);

        $capabilities = $this->validateCapabilities($input['capabilities'] ?? [], $miniapp, $official, $merchant);

        $revision = new StoreWechatSettingRevision($setting, $this->nextRevisionNumber($setting));
        $revision->setMiniappRevision($miniapp)
            ->setOfficialRevision($official)
            ->setMerchantRevision($merchant)
            ->setCapabilities($capabilities);

        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        return $revision;
    }

    public function publish(StoreWechatSettingRevision $revision): void
    {
        if ($revision->getState() !== StoreWechatSettingRevision::STATE_DRAFT) {
            throw new WechatValidationException('Only draft binding revisions can be published.');
        }

        $setting = $revision->getSetting();
        $current = $this->publishedRevision($setting);
        if ($current instanceof StoreWechatSettingRevision) {
            $current->supersede();
        }

        $revision->publish();
        $this->entityManager->flush();
    }

    public function publishedRevision(StoreWechatSetting $setting): ?StoreWechatSettingRevision
    {
        $published = $this->entityManager
            ->getRepository(StoreWechatSettingRevision::class)
            ->findOneBy(['setting' => $setting, 'state' => StoreWechatSettingRevision::STATE_PUBLISHED]);

        return $published instanceof StoreWechatSettingRevision ? $published : null;
    }

    public function disable(StoreWechatSetting $setting): void
    {
        $setting->setEnabled(false);
        $this->entityManager->flush();
    }

    private function publishedApplicationRevision(?string $uuid, string $expectedType): ?WechatApplicationRevision
    {
        if ($uuid === null) {
            return null;
        }

        $revision = $this->entityManager
            ->getRepository(WechatApplicationRevision::class)
            ->findOneBy(['uuid' => $uuid]);

        if (!$revision instanceof WechatApplicationRevision) {
            throw new WechatValidationException(sprintf('Unknown %s application revision.', $expectedType));
        }
        if (!$revision->isPublished()) {
            throw new WechatValidationException('Only published application revisions can be bound.');
        }
        if ($revision->getApplication()->getType() !== $expectedType) {
            throw new WechatValidationException(sprintf('Revision is not a %s application revision.', $expectedType));
        }

        return $revision;
    }

    private function publishedMerchantRevision(?string $uuid): ?WechatMerchantRevision
    {
        if ($uuid === null) {
            return null;
        }

        $revision = $this->entityManager
            ->getRepository(WechatMerchantRevision::class)
            ->findOneBy(['uuid' => $uuid]);

        if (!$revision instanceof WechatMerchantRevision) {
            throw new WechatValidationException('Unknown merchant revision.');
        }
        if (!$revision->isPublished()) {
            throw new WechatValidationException('Only published merchant revisions can be bound.');
        }

        return $revision;
    }

    /**
     * @param array<string, mixed> $requested
     * @return array<string, bool>
     */
    private function validateCapabilities(
        array $requested,
        ?WechatApplicationRevision $miniapp,
        ?WechatApplicationRevision $official,
        ?WechatMerchantRevision $merchant,
    ): array {
        $capabilities = [];
        foreach ($requested as $capability => $enabled) {
            if (!array_key_exists((string) $capability, StoreWechatSettingRevision::CAPABILITY_APPLICATION_TYPE)) {
                throw new WechatValidationException(sprintf('Unknown WeChat capability "%s".', (string) $capability));
            }

            if (!$enabled) {
                $capabilities[(string) $capability] = false;
                continue;
            }

            $applicationType = StoreWechatSettingRevision::CAPABILITY_APPLICATION_TYPE[(string) $capability];
            $applicationRevision = $applicationType === WechatApplication::TYPE_MINIAPP ? $miniapp : $official;
            if (!$applicationRevision instanceof WechatApplicationRevision) {
                throw new WechatValidationException(sprintf(
                    'Capability "%s" requires a published %s application revision.',
                    (string) $capability,
                    $applicationType,
                ));
            }

            if (in_array((string) $capability, StoreWechatSettingRevision::PAYMENT_CAPABILITIES, true)
                && !$merchant instanceof WechatMerchantRevision) {
                throw new WechatValidationException(sprintf(
                    'Capability "%s" requires a published merchant revision.',
                    (string) $capability,
                ));
            }

            $capabilities[(string) $capability] = true;
        }

        return $capabilities;
    }

    private function nextRevisionNumber(StoreWechatSetting $setting): int
    {
        $max = $this->entityManager->createQuery(
            'SELECT MAX(r.revision) FROM '.StoreWechatSettingRevision::class.' r WHERE r.setting = :setting',
        )->setParameter('setting', $setting)->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
