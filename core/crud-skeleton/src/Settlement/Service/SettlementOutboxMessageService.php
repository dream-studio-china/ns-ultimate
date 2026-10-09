<?php

declare(strict_types=1);

namespace App\Settlement\Service;

use App\Core\Service\BaseService;
use App\Settlement\Entity\SettlementOutboxMessage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<SettlementOutboxMessage> */
final class SettlementOutboxMessageService extends BaseService implements SettlementOutboxMessageServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, SettlementOutboxMessage::class);
    }
}
