<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Service;

use App\Core\Service\BaseService;
use NsUltimate\Business\Counter\Entity\Counter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<Counter> */
final class CounterService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Counter::class);
    }
}
