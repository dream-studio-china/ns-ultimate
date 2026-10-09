<?php

declare(strict_types=1);

namespace App\Wechat\Service;

use App\Core\Service\BaseService;
use App\Wechat\Entity\WechatUser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Wechat\Entity\WechatUser> */
final class WechatUserService extends BaseService implements WechatUserServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatUser::class);
    }
}
