<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Core\Service\BaseService;
use App\Settlement\Entity\SettlementAllocation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<SettlementAllocation> */
final class SettlementAllocationService extends BaseService implements SettlementAllocationServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, SettlementAllocation::class);
    }
}
