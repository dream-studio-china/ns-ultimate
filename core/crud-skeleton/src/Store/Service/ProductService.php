<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\Product;

/** @extends BaseService<\App\Store\Entity\Product> */
final class ProductService extends BaseService implements ProductServiceInterface
{
    public function __construct(
        \Symfony\Component\DependencyInjection\ContainerInterface $container,
    ) {
        parent::__construct($container, Product::class);
    }
}
