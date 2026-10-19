<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Store\Entity\Product as CoreProduct;
use App\Store\Service\ProductServiceInterface;
use NsUltimate\Business\Store\Entity\Product;
use NsUltimate\Integration\Backend\Doctrine\EntityExtensionQueryBuilderFactory;
use NsUltimate\Integration\Backend\ExtendedEntityService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends ExtendedEntityService<CoreProduct> */
final class ProductService extends ExtendedEntityService implements ProductServiceInterface
{
    public function __construct(ContainerInterface $container, EntityExtensionQueryBuilderFactory $extensionQueries)
    {
        parent::__construct($container, CoreProduct::class, $extensionQueries);
    }

    public function new(): Product
    {
        return new Product();
    }
}
