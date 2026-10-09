<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Identity\Entity\User;

interface AuthorizationServiceInterface
{
    public function can(User $user, string $permission, ?AuthorizationScope $scope = null): bool;

    public function require(User $user, string $permission, ?AuthorizationScope $scope = null): void;

    /**
     * @return list<string>
     */
    public function allowedStoreUuids(User $user, string $permission): array;

    /**
     * @return array{permissions: list<string>, storeScopes: array<string, list<string>>}
     */
    public function effectivePermissions(User $user): array;
}
