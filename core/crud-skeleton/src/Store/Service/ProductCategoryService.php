<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\ProductCategory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<ProductCategory> */
final class ProductCategoryService extends BaseService implements ProductCategoryServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, ProductCategory::class);
    }
}
