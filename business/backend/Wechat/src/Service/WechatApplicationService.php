<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<WechatApplication> */
final class WechatApplicationService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatApplication::class);
    }
}
