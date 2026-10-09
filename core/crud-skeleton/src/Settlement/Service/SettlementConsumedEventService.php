<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Core\Service\BaseService;
use App\Settlement\Entity\SettlementConsumedEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<SettlementConsumedEvent> */
final class SettlementConsumedEventService extends BaseService implements SettlementConsumedEventServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, SettlementConsumedEvent::class);
    }
}
