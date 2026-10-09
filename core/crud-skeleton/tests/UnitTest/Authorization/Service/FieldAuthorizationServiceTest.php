<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Authorization\Service;

use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Authorization\Entity\RoleFieldGrant;
use App\Authorization\Repository\AssignmentRepository;
use App\Authorization\Repository\RoleFieldGrantRepository;
use App\Authorization\Service\AuthorizationResourceRegistry;
use App\Authorization\Service\AuthorizationScope;
use App\Authorization\Service\AuthorizationServiceInterface;
use App\Authorization\Service\FieldAuthorizationService;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
final class FieldAuthorizationServiceTest extends TestCase
{
    private const RESOURCE = 'common:content';
    private const ACTION_UPDATE = 'update';
    private const ACTION_CREATE = 'create';

    private string $storeXUuid = '11111111-1111-4111-8111-111111111111';
    private string $storeYUuid = '22222222-2222-4222-8222-222222222222';
    private string $userUuid = '33333333-3333-4333-8333-333333333333';

    /** @var list<string> */
    private array $schemaFields = ['title', 'body', 'category', 'tags', 'metadata'];

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------
    private function createUser(string $uuid, array $roles = []): User
    {
        $user = new User();
        $user->setEmail('u_'.$uuid.'@example.test');
        $user->setUsername('u_'.substr($uuid, 0, 8));
        $user->setRoles($roles);
        (new \ReflectionProperty(User::class, 'uuid'))->setValue($user, $uuid);

        return $user;
    }

    private function createRole(string $code, string $scopeType, int $id, string $name = 'Test Role'): Role
    {
        $role = new Role($code, $name, $scopeType);
        (new \ReflectionProperty(Role::class, 'id'))->setValue($role, $id);

        return $role;
    }

    private function createAssignment(Role $role, string $userUuid, string $scopeType, ?string $scopeUuid): Assignment
    {
        return new Assignment($role, $userUuid, $scopeType, $scopeUuid);
    }

    private function createGrant(Role $role, string $resource, string $action, array $fields): RoleFieldGrant
    {
        return new RoleFieldGrant($role, $resource, $action, $fields);
    }

    private function createRegistry(?array $allowedFieldsForCommonContent = null): AuthorizationResourceRegistry
    {
        if ($allowedFieldsForCommonContent === null) {
            // Registry that returns null for common:content (unknown resource). Use dummy resource so lookup misses.
            return new AuthorizationResourceRegistry(['_dummy:resource' => ['_dummy' => ['x']]]);
        }

        return new AuthorizationResourceRegistry([
            self::RESOURCE => [
                self::ACTION_UPDATE => $allowedFieldsForCommonContent,
                self::ACTION_CREATE => $allowedFieldsForCommonContent,
            ],
        ]);
    }

    /**
     * For tests needing null registry for any resource (e.g. custom:thing), provide a registry that never matches.
     */
    private function createNullRegistry(): AuthorizationResourceRegistry
    {
        return new AuthorizationResourceRegistry(['_dummy:resource' => ['_dummy' => ['x']]]);
    }

    /**
     * @param list<Assignment> $assignments
     * @param list<RoleFieldGrant> $grants
     * @param list<string>|null $registryAllowed null means registry returns null for common:content (use dummy). Use createNullRegistry for fully null.
     */
    private function buildService(
        array $assignments,
        array $grants,
        ?array $registryAllowed,
        bool $canResult,
    ): FieldAuthorizationService {
        $assignmentRepo = $this->createMock(AssignmentRepository::class);
        $assignmentRepo->method('findActiveByUser')->willReturn($assignments);

        $grantRepo = $this->createMock(RoleFieldGrantRepository::class);
        $grantRepo->method('findByRoleIds')->willReturnCallback(
            static fn (array $roleIds): array => array_values(array_filter(
                $grants,
                static fn (RoleFieldGrant $g): bool => \in_array($g->getRole()->getId(), $roleIds, true),
            )),
        );

        $registry = $this->createRegistry($registryAllowed);

        $authService = $this->createMock(AuthorizationServiceInterface::class);
        $authService->method('can')->willReturn($canResult);

        return new FieldAuthorizationService($assignmentRepo, $grantRepo, $registry, $authService);
    }

    // -----------------------------------------------------------------
    // Happy path: user has permission and requested fields subset of grant
    // -----------------------------------------------------------------
    public function testFilterWritableFieldsHappyPathSubsetOfGrant(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_content_metadata_editor', Role::SCOPE_STORE, 10);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category', 'tags', 'metadata']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $input = ['title' => 'Hello', 'body' => 'World'];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);

        self::assertSame($input, $result);
    }

    public function testFilterWritableFieldsHappyPathFullGrantSubset(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_content_metadata_editor', Role::SCOPE_STORE, 11);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, $this->schemaFields);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $input = ['title' => 'A', 'metadata' => ['k' => 'v'], 'tags' => ['php']];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);

        self::assertSame($input, $result);
    }

    // -----------------------------------------------------------------
    // Sad path: requesting field not in grant throws
    // -----------------------------------------------------------------
    public function testSadPathFieldNotInGrantThrows(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_content_editor', Role::SCOPE_STORE, 20);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        // editor without metadata
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category', 'tags']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessageMatches('/Fields not allowed for "common:content:update"/');

        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 'ok', 'metadata' => ['x' => 1]], $this->schemaFields, $scope);
    }

    public function testSadPathRequestingBodyNotInGrantThrows(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('limited_editor', Role::SCOPE_STORE, 21);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 'ok', 'body' => 'should fail'], $this->schemaFields, $scope);
    }

    // -----------------------------------------------------------------
    // Metadata field distinction: store_content_editor vs store_content_metadata_editor
    // -----------------------------------------------------------------
    public function testMetadataDeniedForStoreContentEditor(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $editorRole = $this->createRole('store_content_editor', Role::SCOPE_STORE, 30);
        $assignment = $this->createAssignment($editorRole, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($editorRole, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category', 'tags']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 'X', 'metadata' => ['foo' => 'bar']], $this->schemaFields, $scope);
    }

    public function testMetadataAllowedForStoreContentMetadataEditor(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $metaRole = $this->createRole('store_content_metadata_editor', Role::SCOPE_STORE, 31);
        $assignment = $this->createAssignment($metaRole, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($metaRole, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category', 'tags', 'metadata']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $input = ['title' => 'X Updated by B', 'metadata' => ['key' => 'valueB']];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);

        self::assertSame($input, $result);
    }

    public function testEditorWithoutMetadataStillAllowsNonMetadataFields(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $editorRole = $this->createRole('store_content_editor', Role::SCOPE_STORE, 32);
        $assignment = $this->createAssignment($editorRole, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($editorRole, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category', 'tags']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $input = ['title' => 'X Updated by A', 'body' => 'new body A'];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);

        self::assertSame($input, $result);
    }

    // -----------------------------------------------------------------
    // Multiple assignments union + intersection with schema
    // -----------------------------------------------------------------
    public function testMultipleAssignmentsUnionFieldsHappyPath(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $roleA = $this->createRole('role_a', Role::SCOPE_STORE, 40);
        $roleB = $this->createRole('role_b', Role::SCOPE_STORE, 41);
        $assignmentA = $this->createAssignment($roleA, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $assignmentB = $this->createAssignment($roleB, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);

        $grantA = $this->createGrant($roleA, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body']);
        $grantB = $this->createGrant($roleB, self::RESOURCE, self::ACTION_UPDATE, ['category', 'tags']);

        $service = $this->buildService([$assignmentA, $assignmentB], [$grantA, $grantB], $this->schemaFields, true);

        $input = ['title' => 't', 'category' => 'c', 'tags' => ['x']];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);

        self::assertSame($input, $result);
    }

    public function testMultipleAssignmentsUnionSadPathFieldOutsideUnionThrows(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $roleA = $this->createRole('role_a', Role::SCOPE_STORE, 42);
        $roleB = $this->createRole('role_b', Role::SCOPE_STORE, 43);
        $assignmentA = $this->createAssignment($roleA, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $assignmentB = $this->createAssignment($roleB, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);

        $grantA = $this->createGrant($roleA, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body']);
        $grantB = $this->createGrant($roleB, self::RESOURCE, self::ACTION_UPDATE, ['category']);

        $service = $this->buildService([$assignmentA, $assignmentB], [$grantA, $grantB], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't', 'metadata' => ['k' => 1]], $this->schemaFields, $scope);
    }

    public function testUnionIntersectionWithSchemaHappyPath(): void
    {
        // Grants contain fields outside schema, registry intersection should trim
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $roleA = $this->createRole('role_a', Role::SCOPE_STORE, 44);
        $roleB = $this->createRole('role_b', Role::SCOPE_STORE, 45);
        $assignmentA = $this->createAssignment($roleA, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $assignmentB = $this->createAssignment($roleB, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);

        // grant includes 'secret' which is not in schema/registry
        $grantA = $this->createGrant($roleA, self::RESOURCE, self::ACTION_UPDATE, ['title', 'secret']);
        $grantB = $this->createGrant($roleB, self::RESOURCE, self::ACTION_UPDATE, ['body', 'category']);

        // registry allows only schemaFields (no secret)
        $service = $this->buildService([$assignmentA, $assignmentB], [$grantA, $grantB], $this->schemaFields, true);

        // input with title+body succeeds (union includes both, secret filtered by registry)
        $input = ['title' => 't', 'body' => 'b'];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);
        self::assertSame($input, $result);
    }

    public function testUnionIntersectionWithSchemaSadWhenRequestingRegistryFilteredField(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('role_a', Role::SCOPE_STORE, 46);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        // grant has secret inside, but registry will filter it out
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'secret']);

        // registry filters secret away, so effective allowed is only title
        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        // requesting secret should be denied even though grant technically has it
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['secret' => 'x'], $this->schemaFields, $scope);
    }

    public function testIntersectionWithSchemaLimitsAllowedFields(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('role_a', Role::SCOPE_STORE, 47);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category', 'tags', 'metadata', 'extra']);

        // simulate registry with limited schema (without metadata)
        $narrowRegistry = ['title', 'body', 'category', 'tags'];

        $service = $this->buildService([$assignment], [$grant], $narrowRegistry, true);

        // schema argument is also narrow ( caller passes same as registry normally)
        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 'ok', 'metadata' => ['k' => 1]], $narrowRegistry, $scope);
    }

    // -----------------------------------------------------------------
    // Scope handling
    // -----------------------------------------------------------------
    public function testScopeNullOnlyGlobalAssignmentsConsideredHappy(): void
    {
        $globalRole = $this->createRole('global_editor', Role::SCOPE_GLOBAL, 50);
        $user = $this->createUser($this->userUuid);
        $assignment = $this->createAssignment($globalRole, $this->userUuid, AuthorizationScope::GLOBAL, null);
        $grant = $this->createGrant($globalRole, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, null);
        self::assertSame(['title' => 't'], $result);
    }

    public function testScopeNullIgnoresStoreAssignments(): void
    {
        $storeRole = $this->createRole('store_editor', Role::SCOPE_STORE, 51);
        $user = $this->createUser($this->userUuid);
        $assignment = $this->createAssignment($storeRole, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($storeRole, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, null);
    }

    public function testScopeGlobalOnlyGlobalAssignmentsConsidered(): void
    {
        $globalRole = $this->createRole('global_editor', Role::SCOPE_GLOBAL, 52);
        $storeRole = $this->createRole('store_editor', Role::SCOPE_STORE, 53);

        $user = $this->createUser($this->userUuid);
        $globalAssignment = $this->createAssignment($globalRole, $this->userUuid, AuthorizationScope::GLOBAL, null);
        $storeAssignment = $this->createAssignment($storeRole, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);

        $globalGrant = $this->createGrant($globalRole, self::RESOURCE, self::ACTION_UPDATE, ['title']);
        $storeGrant = $this->createGrant($storeRole, self::RESOURCE, self::ACTION_UPDATE, ['body']);

        // For global scope, only global assignment's grant should be effective
        $service = $this->buildService([$globalAssignment, $storeAssignment], [$globalGrant, $storeGrant], $this->schemaFields, true);

        // body is only from store grant, should be denied for global scope
        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['body' => 'x'], $this->schemaFields, AuthorizationScope::global());
    }

    public function testScopeGlobalHappyWithGlobalGrant(): void
    {
        $globalRole = $this->createRole('global_editor', Role::SCOPE_GLOBAL, 54);
        $user = $this->createUser($this->userUuid);
        $assignment = $this->createAssignment($globalRole, $this->userUuid, AuthorizationScope::GLOBAL, null);
        $grant = $this->createGrant($globalRole, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't', 'body' => 'b'], $this->schemaFields, AuthorizationScope::global());
        self::assertSame(['title' => 't', 'body' => 'b'], $result);
    }

    public function testScopeStoreIncludesGlobalAndMatchingStoreUnion(): void
    {
        $globalRole = $this->createRole('global_editor', Role::SCOPE_GLOBAL, 55);
        $storeRole = $this->createRole('store_editor', Role::SCOPE_STORE, 56);

        $user = $this->createUser($this->userUuid);
        $globalAssignment = $this->createAssignment($globalRole, $this->userUuid, AuthorizationScope::GLOBAL, null);
        $storeAssignment = $this->createAssignment($storeRole, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);

        $globalGrant = $this->createGrant($globalRole, self::RESOURCE, self::ACTION_UPDATE, ['title']);
        $storeGrant = $this->createGrant($storeRole, self::RESOURCE, self::ACTION_UPDATE, ['body']);

        // store scope should union both
        $service = $this->buildService([$globalAssignment, $storeAssignment], [$globalGrant, $storeGrant], $this->schemaFields, true);

        $input = ['title' => 't', 'body' => 'b'];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, AuthorizationScope::store($this->storeXUuid));
        self::assertSame($input, $result);
    }

    public function testScopeStoreIgnoresOtherStoreAssignments(): void
    {
        $roleX = $this->createRole('store_editor_x', Role::SCOPE_STORE, 57);
        $roleY = $this->createRole('store_editor_y', Role::SCOPE_STORE, 58);

        $user = $this->createUser($this->userUuid);
        $assignX = $this->createAssignment($roleX, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $assignY = $this->createAssignment($roleY, $this->userUuid, AuthorizationScope::STORE, $this->storeYUuid);

        $grantX = $this->createGrant($roleX, self::RESOURCE, self::ACTION_UPDATE, ['title']);
        $grantY = $this->createGrant($roleY, self::RESOURCE, self::ACTION_UPDATE, ['body']);

        $service = $this->buildService([$assignX, $assignY], [$grantX, $grantY], $this->schemaFields, true);

        // requesting storeX scope, only grantX should be effective
        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['body' => 'should fail for storeX'], $this->schemaFields, AuthorizationScope::store($this->storeXUuid));
    }

    public function testScopeStoreHappyWithMatchingStoreGrant(): void
    {
        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 59);
        $user = $this->createUser($this->userUuid);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'metadata']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        // correct store succeeds
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 'ok'], $this->schemaFields, AuthorizationScope::store($this->storeXUuid));
        self::assertSame(['title' => 'ok'], $result);

        // other store fails (separate service call with same mocks but different scope arg)
        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 'ok'], $this->schemaFields, AuthorizationScope::store($this->storeYUuid));
    }

    // -----------------------------------------------------------------
    // Sad path when user has no assignment for resource/action
    // -----------------------------------------------------------------
    public function testSadPathNoAssignmentsThrowsEvenIfPermissionMockedTrue(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        // no assignments at all, but can returns true to isolate grant logic
        $service = $this->buildService([], [], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, $scope);
    }

    public function testSadPathGrantForOtherResourceActionNotApplied(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 60);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        // grant for create, not update
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_CREATE, ['title', 'body']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, $scope);
    }

    public function testSadPathGrantForOtherResourceNotApplied(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 61);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, 'store:product', 'update', ['title']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, $scope);
    }

    // -----------------------------------------------------------------
    // Permission check before field resolution
    // -----------------------------------------------------------------
    public function testSadPathMissingPermissionThrows(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 62);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title']);

        // can returns false
        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, false);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessageMatches('/Missing permission "common:content:update"/');
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, $scope);
    }

    public function testCanIsCalledWithCorrectPermissionAndScope(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 63);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title']);

        $assignmentRepo = $this->createMock(AssignmentRepository::class);
        $assignmentRepo->method('findActiveByUser')->willReturn([$assignment]);

        $grantRepo = $this->createMock(RoleFieldGrantRepository::class);
        $grantRepo->method('findByRoleIds')->willReturn([$grant]);

        $registry = $this->createRegistry($this->schemaFields);

        $authService = $this->createMock(AuthorizationServiceInterface::class);
        $authService->expects(self::once())
            ->method('can')
            ->with($user, 'common:content:update', $scope)
            ->willReturn(true);

        $service = new FieldAuthorizationService($assignmentRepo, $grantRepo, $registry, $authService);
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, $scope);
        self::assertSame(['title' => 't'], $result);
    }

    // -----------------------------------------------------------------
    // Admin bypass
    // -----------------------------------------------------------------
    public function testAdminBypassesGrantsAndReturnsInput(): void
    {
        $admin = $this->createUser($this->userUuid, ['ROLE_ADMIN']);
        $scope = AuthorizationScope::store($this->storeXUuid);

        // Even with no assignments/grants, admin succeeds
        $assignmentRepo = $this->createMock(AssignmentRepository::class);
        $grantRepo = $this->createMock(RoleFieldGrantRepository::class);
        $registry = $this->createRegistry($this->schemaFields);
        $authService = $this->createMock(AuthorizationServiceInterface::class);
        $authService->expects(self::never())->method('can');

        $service = new FieldAuthorizationService($assignmentRepo, $grantRepo, $registry, $authService);

        $input = ['title' => 'Admin Title', 'metadata' => ['k' => 1], 'body' => 'b'];
        $result = $service->filterWritableFields($admin, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);
        self::assertSame($input, $result);
    }

    public function testAdminThrowsWhenInputContainsExtraFieldNotInSchema(): void
    {
        $admin = $this->createUser($this->userUuid, ['ROLE_ADMIN']);

        $assignmentRepo = $this->createMock(AssignmentRepository::class);
        $grantRepo = $this->createMock(RoleFieldGrantRepository::class);
        $registry = $this->createRegistry($this->schemaFields);
        $authService = $this->createMock(AuthorizationServiceInterface::class);

        $service = new FieldAuthorizationService($assignmentRepo, $grantRepo, $registry, $authService);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessageMatches('/Fields not allowed by schema/');
        $service->filterWritableFields($admin, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't', 'evil' => 'x'], $this->schemaFields, null);
    }

    public function testAdminEmptyInputReturnsEmpty(): void
    {
        $admin = $this->createUser($this->userUuid, ['ROLE_ADMIN']);
        $assignmentRepo = $this->createMock(AssignmentRepository::class);
        $grantRepo = $this->createMock(RoleFieldGrantRepository::class);
        $registry = $this->createRegistry($this->schemaFields);
        $authService = $this->createMock(AuthorizationServiceInterface::class);

        $service = new FieldAuthorizationService($assignmentRepo, $grantRepo, $registry, $authService);

        $result = $service->filterWritableFields($admin, self::RESOURCE, self::ACTION_UPDATE, [], $this->schemaFields, null);
        self::assertSame([], $result);
    }

    // -----------------------------------------------------------------
    // Registry validation interaction
    // -----------------------------------------------------------------
    public function testRegistryValidationFiltersEffectiveFields(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 70);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        // grant contains title, body, extraNotInRegistry
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'extraNotInRegistry', 'metadata']);

        // registry only allows title, body, category, tags (no metadata, no extra)
        $registryAllowed = ['title', 'body', 'category', 'tags'];
        $service = $this->buildService([$assignment], [$grant], $registryAllowed, true);

        // metadata is in grant but registry filters it out, so it should be denied
        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['metadata' => ['k' => 1]], $registryAllowed, $scope);
    }

    public function testRegistryValidationHappyWithRegistryFilteredGrant(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 71);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category']);

        $registryAllowed = $this->schemaFields; // includes all
        $service = $this->buildService([$assignment], [$grant], $registryAllowed, true);

        $input = ['title' => 't', 'category' => 'c'];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);
        self::assertSame($input, $result);
    }

    public function testRegistryReturnsNullNoFiltering(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('custom_role', Role::SCOPE_STORE, 72);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        // grant for unknown resource action not in registry
        $customResource = 'custom:thing';
        $customAction = 'customAction';
        $grant = $this->createGrant($role, $customResource, $customAction, ['fieldA', 'fieldB']);

        // registry returns null => no intersect filtering in resolveEffectiveFields
        // we need a custom schema that matches grants
        $schema = ['fieldA', 'fieldB', 'fieldC'];

        $assignmentRepo = $this->createMock(AssignmentRepository::class);
        $assignmentRepo->method('findActiveByUser')->willReturn([$assignment]);

        $grantRepo = $this->createMock(RoleFieldGrantRepository::class);
        $grantRepo->method('findByRoleIds')->willReturn([$grant]);

        $registry = $this->createNullRegistry();

        $authService = $this->createMock(AuthorizationServiceInterface::class);
        $authService->method('can')->willReturn(true);

        $service = new FieldAuthorizationService($assignmentRepo, $grantRepo, $registry, $authService);

        $input = ['fieldA' => 'v1'];
        $result = $service->filterWritableFields($user, $customResource, $customAction, $input, $schema, $scope);
        self::assertSame($input, $result);

        // fieldB also allowed
        $input2 = ['fieldA' => 'v1', 'fieldB' => 'v2'];
        $result2 = $service->filterWritableFields($user, $customResource, $customAction, $input2, $schema, $scope);
        self::assertSame($input2, $result2);
    }

    public function testRegistryFiltersGrantFieldsBeforeIntersectionWithSchema(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('role', Role::SCOPE_STORE, 73);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        // grant has fields that include both allowed and disallowed by registry
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body', 'category', 'tags', 'metadata', 'notAllowedByRegistry']);

        // registry allows only title, body, category, tags (metadata filtered)
        $registryAllowed = ['title', 'body', 'category', 'tags'];

        $service = $this->buildService([$assignment], [$grant], $registryAllowed, true);

        // Even if schema argument includes metadata, registry intersection removes it from allowed
        $schemaWithMetadata = $this->schemaFields;
        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't', 'metadata' => ['k' => 1]], $schemaWithMetadata, $scope);
    }

    public function testRegistryInteractionEnsuresDisallowedFieldDeniedEvenIfInSchemaAndGrant(): void
    {
        // Same as above but demonstrate that assertOnlyAllowedFields also intersects with registry
        // we mock registry to return limited set, grant+schema contain metadata, but registry disallows it
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_content_metadata_editor', Role::SCOPE_STORE, 74);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'metadata']);

        $registryAllowed = ['title', 'body', 'category', 'tags']; // metadata not allowed per registry for this test

        $service = $this->buildService([$assignment], [$grant], $registryAllowed, true);

        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['metadata' => ['x' => 1]], $this->schemaFields, $scope);
    }

    // -----------------------------------------------------------------
    // Edge: empty input returns empty when allowed
    // -----------------------------------------------------------------
    public function testEmptyInputReturnsEmptyWhenAllowed(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('store_editor', Role::SCOPE_STORE, 80);
        $assignment = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, [], $this->schemaFields, $scope);
        self::assertSame([], $result);
    }

    public function testDuplicateRoleIdsAreDeduplicated(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $role = $this->createRole('dup_role', Role::SCOPE_STORE, 81);
        // two assignments with same role
        $a1 = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $a2 = $this->createAssignment($role, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($role, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body']);

        $assignmentRepo = $this->createMock(AssignmentRepository::class);
        $assignmentRepo->method('findActiveByUser')->willReturn([$a1, $a2]);

        $grantRepo = $this->createMock(RoleFieldGrantRepository::class);
        // should be called with deduplicated [81]
        $grantRepo->expects(self::once())->method('findByRoleIds')->with([81])->willReturn([$grant]);

        $registry = $this->createRegistry($this->schemaFields);

        $authService = $this->createMock(AuthorizationServiceInterface::class);
        $authService->method('can')->willReturn(true);

        $service = new FieldAuthorizationService($assignmentRepo, $grantRepo, $registry, $authService);
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['title' => 't'], $this->schemaFields, $scope);
        self::assertSame(['title' => 't'], $result);
    }

    public function testDuplicateFieldsInGrantsAreDeduplicated(): void
    {
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $roleA = $this->createRole('role_a', Role::SCOPE_STORE, 82);
        $roleB = $this->createRole('role_b', Role::SCOPE_STORE, 83);
        $a1 = $this->createAssignment($roleA, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $a2 = $this->createAssignment($roleB, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);

        // both grants have overlapping title
        $gA = $this->createGrant($roleA, self::RESOURCE, self::ACTION_UPDATE, ['title', 'body']);
        $gB = $this->createGrant($roleB, self::RESOURCE, self::ACTION_UPDATE, ['title', 'category']);

        $service = $this->buildService([$a1, $a2], [$gA, $gB], $this->schemaFields, true);

        $input = ['title' => 't', 'body' => 'b', 'category' => 'c'];
        $result = $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, $input, $this->schemaFields, $scope);
        self::assertSame($input, $result);
    }

    public function testRevokedAssignmentsAreNotReturnedByRepositorySoNotConsidered(): void
    {
        // This documents that revoked assignments are already filtered by repository findActiveByUser.
        // We simulate by not including revoked assignment in mock return.
        $user = $this->createUser($this->userUuid);
        $scope = AuthorizationScope::store($this->storeXUuid);

        $activeRole = $this->createRole('active_role', Role::SCOPE_STORE, 90);
        // revokedRole would not be in active list, so we only mock active one
        $assignment = $this->createAssignment($activeRole, $this->userUuid, AuthorizationScope::STORE, $this->storeXUuid);
        $grant = $this->createGrant($activeRole, self::RESOURCE, self::ACTION_UPDATE, ['title']);

        $service = $this->buildService([$assignment], [$grant], $this->schemaFields, true);

        // metadata not in active grant => denied
        $this->expectException(AccessDeniedException::class);
        $service->filterWritableFields($user, self::RESOURCE, self::ACTION_UPDATE, ['metadata' => ['k' => 1]], $this->schemaFields, $scope);
    }
}
