<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Entity\Assignment;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @extends BaseService<Assignment>
 */
final class AssignmentService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Assignment::class);
    }
}
