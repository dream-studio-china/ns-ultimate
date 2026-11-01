<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<StoreHuifuSetting> */
final class StoreHuifuSettingService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, StoreHuifuSetting::class);
    }
}
