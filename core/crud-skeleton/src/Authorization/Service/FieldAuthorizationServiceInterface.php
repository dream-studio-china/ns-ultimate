<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Service\AuthorizationScope;
use App\Identity\Entity\User;

interface FieldAuthorizationServiceInterface
{
    /**
     * @param array<string, mixed> $input
     * @param list<string> $schemaFields
     * @return array<string, mixed>
     */
    public function filterWritableFields(User $user, string $resource, string $action, array $input, array $schemaFields, ?AuthorizationScope $scope = null): array;
}
