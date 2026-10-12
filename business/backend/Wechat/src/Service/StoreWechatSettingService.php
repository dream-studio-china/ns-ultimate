<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<StoreWechatSetting> */
final class StoreWechatSettingService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, StoreWechatSetting::class);
    }
}
