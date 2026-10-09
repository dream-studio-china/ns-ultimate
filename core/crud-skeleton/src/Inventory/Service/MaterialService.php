<?php

declare(strict_types=1);

namespace App\Inventory\Service;

use App\Core\Service\BaseService;
use App\Inventory\Entity\Material;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<Material> */
final class MaterialService extends BaseService implements MaterialServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Material::class);
    }
}
