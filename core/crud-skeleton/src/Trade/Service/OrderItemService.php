<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseService;
use App\Trade\Entity\OrderItem;

/** @extends BaseService<\App\Trade\Entity\OrderItem> */
final class OrderItemService extends BaseService implements OrderItemServiceInterface
{
    public function __construct(
        \Symfony\Component\DependencyInjection\ContainerInterface $container,
    ) {
        parent::__construct($container, OrderItem::class);
    }
}
