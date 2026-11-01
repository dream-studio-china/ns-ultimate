<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\HuifuPosDevice;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<HuifuPosDevice> */
final class HuifuPosDeviceService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, HuifuPosDevice::class);
    }
}
