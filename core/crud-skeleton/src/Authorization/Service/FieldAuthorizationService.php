<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Repository\AssignmentRepository;
use App\Authorization\Repository\RoleFieldGrantRepository;
use App\Identity\Entity\User;
use App\Core\View\ApiViewMessages;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class FieldAuthorizationService implements FieldAuthorizationServiceInterface
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly RoleFieldGrantRepository $fieldGrantRepository,
        private readonly AuthorizationResourceRegistry $registry,
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {
    }

    public function filterWritableFields(User $user, string $resource, string $action, array $input, array $schemaFields, ?AuthorizationScope $scope = null): array
    {
        if ($this->isAdmin($user)) {
            // Admin is unrestricted within schema
            $allowed = $schemaFields;
            $this->assertOnlySchemaFields($input, $allowed, $resource, $action);

            return $input;
        }

        // Must have action permission first
        $permission = sprintf('%s:%s', $resource, $action);
        // resource is like common:content, but permission code is common:content:update etc.
        // Ensure permission check passes
        if (!$this->authorizationService->can($user, $permission, $scope)) {
            throw new AccessDeniedException(sprintf(ApiViewMessages::MISSING_PERMISSION, $permission));
        }

        $effectiveFields = $this->resolveEffectiveFields($user, $resource, $action, $scope);

        // Intersect with schema
        $allowed = array_values(array_intersect($schemaFields, $effectiveFields));

        // Strict denial: if input contains any schema field outside allowed, deny
        $this->assertOnlyAllowedFields($input, $allowed, $resource, $action);

        // Return filtered input (only allowed fields that were present)
        $result = [];
        foreach ($input as $k => $v) {
            if (\in_array($k, $allowed, true)) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function resolveEffectiveFields(User $user, string $resource, string $action, ?AuthorizationScope $scope): array
    {
        $assignments = $this->assignmentRepository->findActiveByUser($user->getUuid());
        $roleIds = [];
        foreach ($assignments as $assignment) {
            // Scope filtering: include only assignments matching scope
            if ($scope === null) {
                // No scope: include global assignments and store assignments? For field grants, scope matters.
                // If no scope provided, only consider global assignments
                if ($assignment->getScopeType() !== AuthorizationScope::GLOBAL) {
                    continue;
                }
            } elseif ($scope->isGlobal()) {
                if ($assignment->getScopeType() !== AuthorizationScope::GLOBAL) {
                    continue;
                }
            } elseif ($scope->isStore()) {
                // Include global + matching store
                if ($assignment->getScopeType() === AuthorizationScope::GLOBAL) {
                    // global grants also contribute? According spec, store operation requires store-scoped grant, but field grants union across matching roles for request scope.
                    // So we include global as well for store scope? But store-scoped permission is required, so global field grants shouldn't automatically apply to store? Include both.
                } elseif ($assignment->getScopeUuid() !== $scope->uuid) {
                    continue;
                }
            }
            $roleIds[] = $assignment->getRole()->getId();
        }
        $roleIds = array_values(array_unique(array_filter($roleIds)));

        if ($roleIds === []) {
            return [];
        }

        $grants = $this->fieldGrantRepository->findByRoleIds($roleIds);
        $fields = [];
        foreach ($grants as $grant) {
            if ($grant->getResource() === $resource && $grant->getAction() === $action) {
                foreach ($grant->getFields() as $field) {
                    if (!\in_array($field, $fields, true)) {
                        $fields[] = $field;
                    }
                }
            }
        }

        // Validate against registry
        $allowedSchema = $this->registry->getAllowedFields($resource, $action);
        if ($allowedSchema !== null) {
            $fields = array_values(array_intersect($fields, $allowedSchema));
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     */
    private function assertOnlySchemaFields(array $input, array $allowed, string $resource, string $action): void
    {
        $extra = array_diff(array_keys($input), $allowed);
        if ($extra !== []) {
            throw new AccessDeniedException(sprintf('Fields not allowed by schema for "%s:%s": %s.', $resource, $action, implode(', ', $extra)));
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     */
    private function assertOnlyAllowedFields(array $input, array $allowed, string $resource, string $action): void
    {
        $inputKeys = array_keys($input);
        $effectiveAllowed = array_intersect($allowed, $this->registry->getAllowedFields($resource, $action) ?? $allowed);
        $disallowed = array_diff($inputKeys, $effectiveAllowed);
        if ($disallowed !== []) {
            throw new AccessDeniedException(sprintf('Fields not allowed for "%s:%s": %s.', $resource, $action, implode(', ', $disallowed)));
        }
    }

    private function isAdmin(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
