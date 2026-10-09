<?php

namespace App\Common\Service;

use App\Common\Entity\Category;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Category> */
final class CategoryService extends BaseService implements CategoryServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Category::class);
    }
}
