<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Entity\AuditLog;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @extends BaseService<AuditLog>
 */
final class AuditLogService extends BaseService
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, AuditLog::class);
    }
}
