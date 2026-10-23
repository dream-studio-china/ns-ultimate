<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Contract;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Executes one explicitly bound standard administration write through its normal
 * controller. Implementations must not accept a route, controller class, HTTP
 * method or query string from the caller.
 */
interface AdminCrudExecutor
{
    /**
     * @param array<string, mixed> $changes Canonical fields already approved by the agent adapter.
     * @return string|null Persisted numeric identifier for create/update; null for a bodyless delete.
     */
    public function execute(
        EntityManagerInterface $manager,
        string $entityKey,
        string $action,
        ?string $identifier,
        array $changes,
    ): ?string;
}
