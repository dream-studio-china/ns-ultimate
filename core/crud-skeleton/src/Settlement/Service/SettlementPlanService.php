<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Core\Service\BaseService;
use App\Settlement\Entity\SettlementPlan;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<SettlementPlan> */
final class SettlementPlanService extends BaseService implements SettlementPlanServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, SettlementPlan::class);
    }
}
