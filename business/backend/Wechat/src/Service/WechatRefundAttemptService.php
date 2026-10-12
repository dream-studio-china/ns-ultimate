<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Wechat\Entity\WechatRefundAttempt;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<WechatRefundAttempt> */
final class WechatRefundAttemptService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, WechatRefundAttempt::class);
    }
}
