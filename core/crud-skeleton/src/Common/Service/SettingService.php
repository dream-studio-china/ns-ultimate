<?php

namespace App\Common\Service;

use App\Common\Entity\Setting;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Setting> */
final class SettingService extends BaseService implements SettingServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Setting::class);
    }
}
