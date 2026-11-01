<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\HuifuProviderProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<HuifuProviderProfile> */
final class HuifuProviderProfileService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, HuifuProviderProfile::class);
    }
}
