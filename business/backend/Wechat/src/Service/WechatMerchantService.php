<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\WechatMerchant;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<WechatMerchant> */
final class WechatMerchantService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatMerchant::class);
    }
}
