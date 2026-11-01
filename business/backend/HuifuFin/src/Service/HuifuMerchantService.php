<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchant;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<HuifuMerchant> */
final class HuifuMerchantService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, HuifuMerchant::class);
    }
}
