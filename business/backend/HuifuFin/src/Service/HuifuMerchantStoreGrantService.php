<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\HuifuFin\Entity\HuifuMerchantStoreGrant;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<HuifuMerchantStoreGrant> */
final class HuifuMerchantStoreGrantService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, HuifuMerchantStoreGrant::class);
    }
}
