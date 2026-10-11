<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<WechatMerchant> */
final class WechatMerchantService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatMerchant::class);
    }

    public function isBound(WechatMerchant $merchant): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(setting.id)')
            ->from(StoreWechatSetting::class, 'setting')
            ->leftJoin('setting.merchantRevision', 'merchantRevision')
            ->where('merchantRevision.merchant = :merchant')
            ->setParameter('merchant', $merchant)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }
}
