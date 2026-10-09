<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Authorization\Service;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use App\Authorization\Repository\AssignmentRepository;
use App\Authorization\Service\AuthorizationScope;
use App\Authorization\Service\AuthorizationService;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AllowMockObjectsWithoutExpectations]
final class AuthorizationServiceTest extends TestCase
{
    private const string STORE_A = '11111111-1111-4111-8111-111111111111';
    private const string STORE_B = '22222222-2222-4222-8222-222222222222';
    private const string STORE_C = '33333333-3333-4333-8333-333333333333';

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createUser(string $uuid, array $roles = []): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'uuid'))->setValue($user, $uuid);
        $user->setEmail($uuid.'@test.example.com');
        $user->setUsername('user-'.substr($uuid, 0, 8));
        $user->setRoles($roles);

        return $user;
    }

    private function createPermission(string $code): Permission
    {
        [$module, $resource, $action] = array_pad(explode(':', $code, 3), 3, 'default');
        return new Permission($code, $module, $resource, $action, $code);
    }

    private function createRole(string $code, array $permissionCodes, string $scopeType = AuthorizationScope::GLOBAL): Role
    {
        $role = new Role($code, $code, $scopeType);
        foreach ($permissionCodes as $permissionCode) {
            $role->addPermission($this->createPermission($permissionCode));
        }

        return $role;
    }

    private function createAssignment(Role $role, string $userUuid, string $scopeType, ?string $scopeUuid): Assignment
    {
        return new Assignment($role, $userUuid, $scopeType, $scopeUuid);
    }

    /**
     * Cache mock that actually executes the callback (miss path) mimicking real cache.
     */
    private function createCacheThrough(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed {
                $item = new class implements ItemInterface {
                    public function getKey(): string { return 'k'; }
                    public function get(): mixed { return null; }
                    public function isHit(): bool { return false; }
                    public function set(mixed $value): static { return $this; }
                    public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                    public function expiresAfter(int|\DateInterval|null $time): static { return $this; }
                    public function tag(string|iterable $tags): static { return $this; }
                    public function getMetadata(): array { return []; }
                };

                return $callback($item, false);
            }
        );

        return $cache;
    }

    private function createCacheHit(array $effective): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn($effective);

        return $cache;
    }

    // -----------------------------------------------------------------
    // isAdmin bypass
    // -----------------------------------------------------------------

    public function testIsAdminBypassReturnsTrueRegardlessOfScope(): void
    {
        $adminUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $admin = $this->createUser($adminUuid, ['ROLE_ADMIN']);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->expects(self::never())->method('findActiveByUser');

        $cache = $this->createCacheHit(['permissions' => [], 'globalPermissions' => [], 'storeScopes' => [], 'fieldGrants' => []]);

        $service = new AuthorizationService($repo, $cache);

        self::assertTrue($service->can($admin, 'any:permission:action', null));
        self::assertTrue($service->can($admin, 'any:permission:action', AuthorizationScope::global()));
        self::assertTrue($service->can($admin, 'any:permission:action', AuthorizationScope::store(self::STORE_A)));
    }

    public function testIsAdminBypassThroughCacheThroughPath(): void
    {
        $adminUuid = 'aaaaaaaa-bbbb-4aaa-8aaa-aaaaaaaaaaaa';
        $admin = $this->createUser($adminUuid, ['ROLE_ADMIN']);
        $repo = $this->createMock(AssignmentRepository::class);
        $repo->expects(self::never())->method('findActiveByUser');
        $cache = $this->createCacheThrough();

        $service = new AuthorizationService($repo, $cache);

        // Even with no assignments, admin bypasses everything
        self::assertTrue($service->can($admin, 'missing:perm:read'));
        self::assertTrue($service->can($admin, 'missing:perm:read', AuthorizationScope::store(self::STORE_B)));
    }

    // -----------------------------------------------------------------
    // can() with null scope requires global permission
    // -----------------------------------------------------------------

    public function testCanWithNullScopeRequiresGlobalPermissionGranted(): void
    {
        $userUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_global', ['store:order:read'], AuthorizationScope::GLOBAL);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::GLOBAL, null);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->with($userUuid)->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertTrue($service->can($user, 'store:order:read', null));
        self::assertTrue($service->can($user, 'store:order:read', AuthorizationScope::global()));
    }

    public function testCanWithNullScopeDeniedWhenOnlyStoreAssignment(): void
    {
        $userUuid = 'bbbbbbbb-cccc-4bbb-8bbb-bbbbbbbbbbbb';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_store', ['store:order:read'], AuthorizationScope::STORE);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->with($userUuid)->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertFalse($service->can($user, 'store:order:read', null));
        self::assertFalse($service->can($user, 'store:order:read', AuthorizationScope::global()));
    }

    public function testCanWithNullScopeReturnsFalseWhenPermissionMissing(): void
    {
        $userUuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_global_other', ['store:order:read'], AuthorizationScope::GLOBAL);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::GLOBAL, null);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertFalse($service->can($user, 'store:order:write', null));
    }

    // -----------------------------------------------------------------
    // can() with store scope
    // -----------------------------------------------------------------

    public function testCanWithStoreScopeRequiresMatchingStoreUuid(): void
    {
        $userUuid = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_store_match', ['common:content:create'], AuthorizationScope::STORE);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertTrue($service->can($user, 'common:content:create', AuthorizationScope::store(self::STORE_A)));
        self::assertFalse($service->can($user, 'common:content:create', AuthorizationScope::store(self::STORE_B)));
    }

    public function testCanWithStoreScopeReturnsFalseWhenPermissionNotInEffectiveSet(): void
    {
        $userUuid = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_store_other_perm', ['common:content:create'], AuthorizationScope::STORE);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertFalse($service->can($user, 'common:content:delete', AuthorizationScope::store(self::STORE_A)));
    }

    public function testCanWithStoreScopeWhenMultipleAssignmentsHappyPath(): void
    {
        $userUuid = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_multi_store', ['common:content:create'], AuthorizationScope::STORE);
        $assignmentA = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);
        $assignmentB = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_B);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignmentA, $assignmentB]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertTrue($service->can($user, 'common:content:create', AuthorizationScope::store(self::STORE_A)));
        self::assertTrue($service->can($user, 'common:content:create', AuthorizationScope::store(self::STORE_B)));
    }

    public function testCanWithStoreScopeWhenMultipleAssignmentsWrongUuidDenied(): void
    {
        $userUuid = 'aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_multi_store2', ['common:content:create'], AuthorizationScope::STORE);
        $assignmentA = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);
        $assignmentB = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_B);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignmentA, $assignmentB]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertFalse($service->can($user, 'common:content:create', AuthorizationScope::store(self::STORE_C)));
    }

    public function testCanWithStoreScopeReturnsFalseWhenNoStoreScopeForPermission(): void
    {
        $userUuid = 'bbbbbbbb-1111-4bbb-8bbb-bbbbbbbbbbbb';
        $user = $this->createUser($userUuid);
        // Global only, no storeScopes entry at all
        $role = $this->createRole('role_global_only', ['store:order:read'], AuthorizationScope::GLOBAL);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::GLOBAL, null);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertFalse($service->can($user, 'store:order:read', AuthorizationScope::store(self::STORE_A)));
    }

    // -----------------------------------------------------------------
    // allowedStoreUuids
    // -----------------------------------------------------------------

    public function testAllowedStoreUuidsReturnsCorrectList(): void
    {
        $userUuid = 'cccccccc-1111-4ccc-8ccc-cccccccccccc';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_allowed', ['common:content:create'], AuthorizationScope::STORE);
        $assignmentA = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_B);
        $assignmentB = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignmentA, $assignmentB]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        $uuids = $service->allowedStoreUuids($user, 'common:content:create');

        // computeEffective sorts each storeScopes list
        self::assertSame([self::STORE_A, self::STORE_B], $uuids);
    }

    public function testAllowedStoreUuidsReturnsEmptyForAdmin(): void
    {
        $adminUuid = 'dddddddd-1111-4ddd-8ddd-dddddddddddd';
        $admin = $this->createUser($adminUuid, ['ROLE_ADMIN']);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->expects(self::never())->method('findActiveByUser');

        $cache = $this->createCacheThrough();
        $service = new AuthorizationService($repo, $cache);

        self::assertSame([], $service->allowedStoreUuids($admin, 'any:perm:read'));
    }

    public function testAllowedStoreUuidsReturnsEmptyForMissingPermission(): void
    {
        $userUuid = 'eeeeeeee-1111-4eee-8eee-eeeeeeeeeeee';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_allowed2', ['common:content:create'], AuthorizationScope::STORE);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertSame([], $service->allowedStoreUuids($user, 'non:existent:perm'));
    }

    public function testAllowedStoreUuidsReturnsEmptyWhenNoAssignments(): void
    {
        $userUuid = 'ffffffff-1111-4fff-8fff-ffffffffffff';
        $user = $this->createUser($userUuid);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        self::assertSame([], $service->allowedStoreUuids($user, 'common:content:create'));
    }

    // -----------------------------------------------------------------
    // effectivePermissions structure sorting
    // -----------------------------------------------------------------

    public function testEffectivePermissionsStructureSorting(): void
    {
        $userUuid = 'aaaaaaaa-2222-4aaa-8aaa-aaaaaaaaaaaa';
        $user = $this->createUser($userUuid);

        // Permissions unsorted intentionally
        $roleGlobal = $this->createRole('role_g', ['z:perm:read', 'a:perm:write'], AuthorizationScope::GLOBAL);
        $roleStore = $this->createRole('role_s', ['a:perm:write', 'm:perm:delete'], AuthorizationScope::STORE);

        $assignGlobal = $this->createAssignment($roleGlobal, $userUuid, AuthorizationScope::GLOBAL, null);
        // Provide store assignments in reverse order to test sorting
        $assignStoreB = $this->createAssignment($roleStore, $userUuid, AuthorizationScope::STORE, self::STORE_B);
        $assignStoreA = $this->createAssignment($roleStore, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignGlobal, $assignStoreB, $assignStoreA]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        $effective = $service->effectivePermissions($user);

        // permissions should be sorted
        self::assertSame(['a:perm:write', 'm:perm:delete', 'z:perm:read'], $effective['permissions']);

        // storeScopes values sorted; keys preserved
        self::assertSame([self::STORE_A, self::STORE_B], $effective['storeScopes']['a:perm:write']);
        self::assertSame([self::STORE_A, self::STORE_B], $effective['storeScopes']['m:perm:delete']);
        // z:perm:read has no store scopes
        self::assertArrayNotHasKey('z:perm:read', $effective['storeScopes']);
    }

    public function testEffectivePermissionsDeduplicatesPermissions(): void
    {
        $userUuid = 'bbbbbbbb-2222-4bbb-8bbb-bbbbbbbbbbbb';
        $user = $this->createUser($userUuid);

        $role1 = $this->createRole('role1', ['store:order:read'], AuthorizationScope::GLOBAL);
        $role2 = $this->createRole('role2', ['store:order:read'], AuthorizationScope::STORE);

        $assign1 = $this->createAssignment($role1, $userUuid, AuthorizationScope::GLOBAL, null);
        $assign2 = $this->createAssignment($role2, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assign1, $assign2]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        $effective = $service->effectivePermissions($user);
        self::assertSame(['store:order:read'], $effective['permissions']);
        self::assertSame([self::STORE_A], $effective['storeScopes']['store:order:read']);
    }

    // -----------------------------------------------------------------
    // require() throws
    // -----------------------------------------------------------------

    public function testRequireThrowsAccessDeniedExceptionWhenDenied(): void
    {
        $userUuid = 'cccccccc-2222-4ccc-8ccc-cccccccccccc';
        $user = $this->createUser($userUuid);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Missing permission "store:order:read".');
        $service->require($user, 'store:order:read', AuthorizationScope::store(self::STORE_A));
    }

    public function testRequireDoesNotThrowWhenAllowed(): void
    {
        $userUuid = 'dddddddd-2222-4ddd-8ddd-dddddddddddd';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_req', ['store:order:read'], AuthorizationScope::STORE);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        // Should not throw
        $service->require($user, 'store:order:read', AuthorizationScope::store(self::STORE_A));
        self::addToAssertionCount(1);

        // Global require also
        $roleG = $this->createRole('role_req_g', ['store:order:read'], AuthorizationScope::GLOBAL);
        $assignG = $this->createAssignment($roleG, $userUuid, AuthorizationScope::GLOBAL, null);
        $repo2 = $this->createMock(AssignmentRepository::class);
        $repo2->method('findActiveByUser')->willReturn([$assignG]);
        $service2 = new AuthorizationService($repo2, $this->createCacheThrough());
        $service2->require($user, 'store:order:read', null);
        self::addToAssertionCount(1);
    }

    public function testRequireThrowsForAdminNever(): void
    {
        $adminUuid = 'eeeeeeee-2222-4eee-8eee-eeeeeeeeeeee';
        $admin = $this->createUser($adminUuid, ['ROLE_ADMIN']);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->expects(self::never())->method('findActiveByUser');

        $service = new AuthorizationService($repo, $this->createCacheThrough());

        $service->require($admin, 'any:perm:action', AuthorizationScope::store(self::STORE_A));
        self::addToAssertionCount(1);
    }

    // -----------------------------------------------------------------
    // cache hit path and fallback when cache throws
    // -----------------------------------------------------------------

    public function testCacheHitPathDoesNotQueryRepository(): void
    {
        $userUuid = 'ffffffff-2222-4fff-8fff-ffffffffffff';
        $user = $this->createUser($userUuid);

        $cachedEffective = [
            'permissions' => ['store:order:read'],
            'globalPermissions' => ['store:order:read'],
            'storeScopes' => [],
            'fieldGrants' => [],
        ];

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->expects(self::never())->method('findActiveByUser');

        $cache = $this->createCacheHit($cachedEffective);
        $service = new AuthorizationService($repo, $cache);

        self::assertTrue($service->can($user, 'store:order:read', null));
        self::assertFalse($service->can($user, 'store:order:write', null));
    }

    public function testCacheHitPathForStoreScope(): void
    {
        $userUuid = 'aaaaaaaa-3333-4aaa-8aaa-aaaaaaaaaaaa';
        $user = $this->createUser($userUuid);

        $cachedEffective = [
            'permissions' => ['common:content:create'],
            'globalPermissions' => [],
            'storeScopes' => ['common:content:create' => [self::STORE_A]],
            'fieldGrants' => [],
        ];

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->expects(self::never())->method('findActiveByUser');

        $cache = $this->createCacheHit($cachedEffective);
        $service = new AuthorizationService($repo, $cache);

        self::assertTrue($service->can($user, 'common:content:create', AuthorizationScope::store(self::STORE_A)));
        self::assertFalse($service->can($user, 'common:content:create', AuthorizationScope::store(self::STORE_B)));
        self::assertSame([self::STORE_A], $service->allowedStoreUuids($user, 'common:content:create'));
    }

    public function testCacheFallbackWhenCacheThrowsLogsWarningAndReturnsDbResult(): void
    {
        $userUuid = 'bbbbbbbb-3333-4bbb-8bbb-bbbbbbbbbbbb';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_fallback', ['store:order:read'], AuthorizationScope::GLOBAL);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::GLOBAL, null);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->expects(self::exactly(2))->method('findActiveByUser')->with($userUuid)->willReturn([$assignment]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('Redis down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning')
            ->with(
                'Authorization cache failure, falling back to DB.',
                self::callback(static function (array $context): bool {
                    return isset($context['exception'], $context['user'])
                        && $context['exception'] === 'Redis down';
                })
            );

        $service = new AuthorizationService($repo, $cache, $logger);

        // Should still succeed via fallback
        self::assertTrue($service->can($user, 'store:order:read', null));
        self::assertFalse($service->can($user, 'store:order:write', null));
    }

    public function testCacheFallbackWhenCacheThrowsOnAllowedStoreUuids(): void
    {
        $userUuid = 'cccccccc-3333-4ccc-8ccc-cccccccccccc';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_fallback2', ['common:content:create'], AuthorizationScope::STORE);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::STORE, self::STORE_A);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('Cache failure'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new AuthorizationService($repo, $cache, $logger);

        self::assertSame([self::STORE_A], $service->allowedStoreUuids($user, 'common:content:create'));
    }

    public function testCacheFallbackWhenCacheThrowsOnEffectivePermissions(): void
    {
        $userUuid = 'dddddddd-3333-4ddd-8ddd-dddddddddddd';
        $user = $this->createUser($userUuid);
        $role = $this->createRole('role_eff', ['z:perm:read', 'a:perm:write'], AuthorizationScope::GLOBAL);
        $assignment = $this->createAssignment($role, $userUuid, AuthorizationScope::GLOBAL, null);

        $repo = $this->createMock(AssignmentRepository::class);
        $repo->method('findActiveByUser')->willReturn([$assignment]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('Cache down'));

        $service = new AuthorizationService($repo, $cache);

        $effective = $service->effectivePermissions($user);
        self::assertSame(['a:perm:write', 'z:perm:read'], $effective['permissions']);
    }
}
