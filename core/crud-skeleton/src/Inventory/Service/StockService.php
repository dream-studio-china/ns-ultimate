<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Core\Service\BaseService;
use App\Inventory\Entity\Stock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<Stock> */
final class StockService extends BaseService implements StockServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Stock::class);
    }
}
