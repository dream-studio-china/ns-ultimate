<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use App\Core\Utils\UUID;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatMerchantRevision;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @extends BaseService<StoreWechatSetting>
 */
final class StoreWechatSettingService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, StoreWechatSetting::class);
    }

    public function resolveApplicationRevision(string|int|null $identifier, string $expectedType): ?WechatApplicationRevision
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $revision = is_string($identifier) && UUID::is_valid($identifier)
            ? $this->em->getRepository(WechatApplicationRevision::class)->findOneBy(['uuid' => $identifier])
            : $this->em->getRepository(WechatApplicationRevision::class)->find($identifier);
        if (!$revision instanceof WechatApplicationRevision) {
            throw new \InvalidArgumentException(sprintf('Unknown %s application revision.', $expectedType));
        }
        if (!$revision->isPublished()) {
            throw new \InvalidArgumentException('Only published application revisions can be bound.');
        }
        if ($revision->getApplication()?->getType() !== $expectedType) {
            throw new \InvalidArgumentException(sprintf('Revision is not a %s application revision.', $expectedType));
        }

        return $revision;
    }

    public function resolveMerchantRevision(string|int|null $identifier): ?WechatMerchantRevision
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $revision = is_string($identifier) && UUID::is_valid($identifier)
            ? $this->em->getRepository(WechatMerchantRevision::class)->findOneBy(['uuid' => $identifier])
            : $this->em->getRepository(WechatMerchantRevision::class)->find($identifier);
        if (!$revision instanceof WechatMerchantRevision) {
            throw new \InvalidArgumentException('Unknown merchant revision.');
        }
        if (!$revision->isPublished()) {
            throw new \InvalidArgumentException('Only published merchant revisions can be bound.');
        }

        return $revision;
    }

    /**
     * @param array<string, mixed> $requested
     * @return array<string, bool>
     */
    public function normalizeCapabilities(
        array $requested,
        ?WechatApplicationRevision $miniapp,
        ?WechatApplicationRevision $official,
        ?WechatMerchantRevision $merchant,
    ): array {
        $capabilities = [];
        foreach ($requested as $capability => $enabled) {
            $capability = (string) $capability;
            if (!array_key_exists($capability, StoreWechatSetting::CAPABILITY_APPLICATION_TYPE)) {
                throw new \InvalidArgumentException(sprintf('Unknown WeChat capability "%s".', $capability));
            }

            if (!$enabled) {
                $capabilities[$capability] = false;
                continue;
            }

            $applicationType = StoreWechatSetting::CAPABILITY_APPLICATION_TYPE[$capability];
            $applicationRevision = $applicationType === WechatApplication::TYPE_MINIAPP ? $miniapp : $official;
            if (!$applicationRevision instanceof WechatApplicationRevision) {
                throw new \InvalidArgumentException(sprintf(
                    'Capability "%s" requires a published %s application revision.',
                    $capability,
                    $applicationType,
                ));
            }

            if (in_array($capability, StoreWechatSetting::PAYMENT_CAPABILITIES, true) && !$merchant instanceof WechatMerchantRevision) {
                throw new \InvalidArgumentException(sprintf(
                    'Capability "%s" requires a published merchant revision.',
                    $capability,
                ));
            }

            $capabilities[$capability] = true;
        }

        return $capabilities;
    }
}
