<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Entity\Role;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @extends BaseService<Role>
 */
final class RoleService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Role::class);
    }
}
