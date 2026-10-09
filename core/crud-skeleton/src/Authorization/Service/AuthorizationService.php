<?php

declare(strict_types=1);

namespace App\Authorization\Service;

use App\Authorization\Repository\AssignmentRepository;
use App\Identity\Entity\User;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use App\Core\View\ApiViewMessages;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AuthorizationService implements AuthorizationServiceInterface
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function can(User $user, string $permission, ?AuthorizationScope $scope = null): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $effective = $this->getEffective($user);

        if ($scope === null) {
            return \in_array($permission, $effective['globalPermissions'], true);
        }

        if ($scope->isGlobal()) {
            return \in_array($permission, $effective['globalPermissions'] ?? [], true);
        }

        // Store scope
        $storeScopes = $effective['storeScopes'][$permission] ?? [];
        if ($storeScopes === []) {
            return false;
        }

        return \in_array($scope->uuid, $storeScopes, true);
    }

    public function require(User $user, string $permission, ?AuthorizationScope $scope = null): void
    {
        if (!$this->can($user, $permission, $scope)) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException(sprintf(ApiViewMessages::MISSING_PERMISSION, $permission));
        }
    }

    public function allowedStoreUuids(User $user, string $permission): array
    {
        if ($this->isAdmin($user)) {
            // Admin is unrestricted: return empty meaning no filter? But design says no Store predicate for admin.
            // Return empty list to signal unrestricted? Caller should check isAdmin.
            // For convenience, return all store uuids where user has permission plus global implies all?
            // We'll return unique store uuids from effective, but admin gets empty to indicate no filter.
            return [];
        }

        $effective = $this->getEffective($user);
        return $effective['storeScopes'][$permission] ?? [];
    }

    public function effectivePermissions(User $user): array
    {
        $effective = $this->getEffective($user);

        return [
            'permissions' => $effective['permissions'],
            'storeScopes' => $effective['storeScopes'],
        ];
    }

    /**
     * @return array{permissions: list<string>, globalPermissions: list<string>, storeScopes: array<string, list<string>>, fieldGrants: array<string, list<string>>}
     */
    private function getEffective(User $user): array
    {
        $uuid = $user->getUuid();
        $key = 'authorization_effective_'.$uuid;

        // Use cache with 5 minutes TTL, fallback to DB
        try {
            return $this->cache->get($key, function (ItemInterface $item) use ($user): array {
                $item->expiresAfter(300);
                return $this->computeEffective($user);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Authorization cache failure, falling back to DB.', ['exception' => $e->getMessage(), 'user' => $uuid]);
            return $this->computeEffective($user);
        }
    }

    /**
     * @return array{permissions: list<string>, globalPermissions: list<string>, storeScopes: array<string, list<string>>, fieldGrants: array<string, list<string>>}
     */
    private function computeEffective(User $user): array
    {
        $assignments = $this->assignmentRepository->findActiveByUser($user->getUuid());
        $permissions = [];
        $globalPermissions = [];
        $storeScopes = [];

        foreach ($assignments as $assignment) {
            $role = $assignment->getRole();
            foreach ($role->getPermissions() as $permission) {
                $code = $permission->getCode();
                if (!\in_array($code, $permissions, true)) {
                    $permissions[] = $code;
                }
                if ($assignment->getScopeType() === AuthorizationScope::STORE && $assignment->getScopeUuid() !== null) {
                    $storeScopes[$code] ??= [];
                    if (!\in_array($assignment->getScopeUuid(), $storeScopes[$code], true)) {
                        $storeScopes[$code][] = $assignment->getScopeUuid();
                    }
                } elseif ($assignment->getScopeType() === AuthorizationScope::GLOBAL) {
                    if (!\in_array($code, $globalPermissions, true)) {
                        $globalPermissions[] = $code;
                    }
                }
            }
        }

        sort($permissions);
        sort($globalPermissions);
        foreach ($storeScopes as &$list) {
            sort($list);
        }
        unset($list);

        return [
            'permissions' => $permissions,
            'globalPermissions' => $globalPermissions,
            'storeScopes' => $storeScopes,
            'fieldGrants' => [],
        ];
    }

    private function isAdmin(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
