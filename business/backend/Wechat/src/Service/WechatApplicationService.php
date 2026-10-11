<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<WechatApplication> */
final class WechatApplicationService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatApplication::class);
    }

    public function isBound(WechatApplication $application): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(setting.id)')
            ->from(StoreWechatSetting::class, 'setting')
            ->leftJoin('setting.miniappRevision', 'miniapp')
            ->leftJoin('setting.officialRevision', 'official')
            ->where('miniapp.application = :application OR official.application = :application')
            ->setParameter('application', $application)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }
}
