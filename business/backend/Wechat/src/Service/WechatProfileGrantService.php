<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\WechatProfileGrant;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @extends BaseService<WechatProfileGrant>
 */
final class WechatProfileGrantService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatProfileGrant::class);
    }

    public function isGranted(string $storeUuid, string $profileType, string $profileUuid): bool
    {
        return $this->rep->findOneBy([
            'storeUuid' => $storeUuid,
            'profileType' => $profileType,
            'profileUuid' => $profileUuid,
        ]) instanceof WechatProfileGrant;
    }

    public function assertGranted(string $storeUuid, string $profileType, string $profileUuid): void
    {
        if (!$this->isGranted($storeUuid, $profileType, $profileUuid)) {
            throw new \InvalidArgumentException(sprintf(
                'The store is not granted access to this %s profile.',
                $profileType,
            ));
        }
    }
}
