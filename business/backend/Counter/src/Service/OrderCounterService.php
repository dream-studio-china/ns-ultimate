<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Counter\Entity\OrderCounter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<OrderCounter> */
final class OrderCounterService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, OrderCounter::class);
    }
}
