<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Entity\Permission;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @extends BaseService<Permission>
 */
final class PermissionService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Permission::class);
    }
}
