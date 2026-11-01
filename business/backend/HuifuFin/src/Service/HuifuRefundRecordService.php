<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\HuifuRefund;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<HuifuRefund> */
final class HuifuRefundRecordService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, HuifuRefund::class);
    }
}
