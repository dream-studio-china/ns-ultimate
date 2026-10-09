<?php

declare(strict_types=1);

namespace App\Authorization\Controller\Manage;

use App\Authorization\Entity\Role;
use App\Authorization\Repository\AssignmentRepository;
use App\Authorization\Service\AuthorizationAuditService;
use App\Authorization\Service\AuthorizationCacheInvalidator;
use App\Authorization\Service\AuthorizationResourceRegistry;
use App\Authorization\Service\RoleService;
use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/roles', name: 'manage-roles-')]
#[IsGranted('ROLE_ADMIN')]
class RoleController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['code', 'name', 'scopeType'];

    /** @var list<string> */
    protected array $acceptedCreateProperties = ['code', 'name', 'scopeType', 'uuid'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['code', 'name'];

    public function __construct(
        protected readonly RoleService $service,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AuthorizationAuditService $auditService,
        private readonly AuthorizationCacheInvalidator $cacheInvalidator,
        private readonly AuthorizationResourceRegistry $registry,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        $code = trim((string) ($content['code'] ?? ''));
        $name = trim((string) ($content['name'] ?? ''));
        $scopeType = trim((string) ($content['scopeType'] ?? ''));

        if (!\in_array($scopeType, Role::SCOPES, true)) {
            throw new \InvalidArgumentException('Invalid scopeType, expected global or store');
        }
        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            throw new \InvalidArgumentException('Invalid role code, expected [a-z0-9_]');
        }
        if (isset($content['isSystem']) && (bool) $content['isSystem']) {
            throw new \InvalidArgumentException('Cannot create system role via API');
        }

        return $content;
    }

    protected function afterCreated(object|false $entity): mixed
    {
        if (!$entity instanceof Role) {
            return $entity;
        }
        $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
        $this->auditService->record($actorUuid, 'role.created', 'role', $entity->getUuid(), null, ['code' => $entity->getCode(), 'scopeType' => $entity->getScopeType()]);
        $this->em->flush();

        return $entity;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof Role) {
            return $content;
        }
        if ($entity->isSystem()) {
            throw new \InvalidArgumentException('System role cannot be modified');
        }
        if (isset($content['code'])) {
            $newCode = trim((string) $content['code']);
            if (!preg_match('/^[a-z0-9_]+$/', $newCode)) {
                throw new \InvalidArgumentException('Invalid role code');
            }
        }

        return $content;
    }

    protected function afterUpdated(object|false $entity): mixed
    {
        if (!$entity instanceof Role) {
            return $entity;
        }
        $roleId = $entity->getId();
        \assert($roleId !== null);
        $userUuids = $this->assignmentRepository->findActiveUserUuidsByRole($roleId);
        $this->cacheInvalidator->invalidateUsers($userUuids);

        return $entity;
    }

    #[Route('/{uuid}/permissions', name: 'replace-permissions', methods: ['POST'])]
    public function replacePermissions(string $uuid, Request $request): Response
    {
        if (!UUID::is_valid($uuid)) {
            return $this->warning('Invalid uuid', 1, null, 400);
        }
        $role = $this->service->get(['uuid' => $uuid]);
        if (!$role instanceof Role) {
            return $this->warning('Entity is not found', 1, null, 404);
        }
        if ($role->isSystem()) {
            return $this->warning('System role permissions cannot be modified via API', 1, null, 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->warning('Invalid JSON', 1, null, 400);
        }
        $codes = $data['permissions'] ?? $data['codes'] ?? $data;
        if (!\is_array($codes)) {
            return $this->warning('permissions must be array of codes', 1, null, 400);
        }
        $codes = array_values(array_unique(array_map('strval', $codes)));
        foreach ($codes as $code) {
            if (!preg_match('/^[a-z0-9:_]+$/', $code)) {
                return $this->warning(sprintf('Invalid permission code "%s"', $code), 1, null, 400);
            }
        }

        $em = $this->em;
        $permissionRepo = $em->getRepository(\App\Authorization\Entity\Permission::class);
        $permissions = $permissionRepo->findByCodes($codes);
        $foundCodes = array_map(static fn ($p) => $p->getCode(), $permissions);
        $missing = array_diff($codes, $foundCodes);
        if ($missing !== []) {
            return $this->warning(sprintf('Permissions not found: %s', implode(', ', $missing)), 1, null, 400);
        }

        $before = array_map(static fn ($p) => $p->getCode(), $role->getPermissions()->toArray());
        sort($before);
        $role->getPermissions()->clear();
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $role->touch();

        $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
        $after = $codes;
        sort($after);

        $em->wrapInTransaction(function () use ($role, $actorUuid, $before, $after): void {
            $em = $this->em;
            $em->flush();
            $this->auditService->record($actorUuid, 'role.permissions.replaced', 'role', $role->getUuid(), ['permissions' => $before], ['permissions' => $after]);
            $em->flush();
        });

        $roleId = $role->getId();
        \assert($roleId !== null);
        $userUuids = $this->assignmentRepository->findActiveUserUuidsByRole($roleId);
        $this->cacheInvalidator->invalidateUsers($userUuids);

        return $this->success($role);
    }

    #[Route('/{uuid}/field-grants/{resource}/{action}', name: 'replace-field-grant', methods: ['PUT'])]
    public function replaceFieldGrant(string $uuid, string $resource, string $action, Request $request): Response
    {
        if (!UUID::is_valid($uuid)) {
            return $this->warning('Invalid uuid', 1, null, 400);
        }
        $role = $this->service->get(['uuid' => $uuid]);
        if (!$role instanceof Role) {
            return $this->warning('Entity is not found', 1, null, 404);
        }
        if ($role->isSystem()) {
            return $this->warning('System role field grants cannot be modified via API', 1, null, 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->warning('Invalid JSON', 1, null, 400);
        }
        $fields = $data['fields'] ?? $data;
        if (!\is_array($fields)) {
            return $this->warning('fields must be array', 1, null, 400);
        }
        $fields = array_values(array_unique(array_map('strval', $fields)));

        try {
            $this->registry->assertValidFields($resource, $action, $fields);
        } catch (\InvalidArgumentException $e) {
            return $this->warning($e->getMessage(), 1, null, 400);
        }

        $actorUuid = $this->getUser() instanceof \App\Identity\Entity\User ? $this->getUser()->getUuid() : null;
        $em = $this->em;
        $fieldGrantRepo = $em->getRepository(\App\Authorization\Entity\RoleFieldGrant::class);
        $roleId = $role->getId();
        \assert($roleId !== null);
        $existingGrant = $fieldGrantRepo->findOneBy(['role' => $roleId, 'resource' => $resource, 'action' => $action]);
        $before = $existingGrant?->getFields();

        $em->wrapInTransaction(function () use ($role, $resource, $action, $fields, $existingGrant, $actorUuid, $before): void {
            $em = $this->em;
            if ($existingGrant !== null) {
                $existingGrant->setFields($fields);
                $existingGrant->touch();
            } else {
                $grant = new \App\Authorization\Entity\RoleFieldGrant($role, $resource, $action, $fields);
                $em->persist($grant);
            }
            $em->flush();
            $this->auditService->record($actorUuid, 'field_grant.replaced', 'role', $role->getUuid(), ['resource' => $resource, 'action' => $action, 'fields' => $before], ['resource' => $resource, 'action' => $action, 'fields' => $fields]);
            $em->flush();
        });

        $userUuids = $this->assignmentRepository->findActiveUserUuidsByRole($roleId);
        $this->cacheInvalidator->invalidateUsers($userUuids);

        $grant = $fieldGrantRepo->findOneBy(['role' => $roleId, 'resource' => $resource, 'action' => $action]);

        return $this->success($grant);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function deleteAction(int|string $id): Response
    {
        $role = $this->service->get($this->mixIdToCommonFilter($id), false);
        if (!$role instanceof Role) {
            return $this->warning('Entity is not found', 1, null, 404);
        }
        if ($role->isSystem()) {
            return $this->warning('System role cannot be deleted', 1, null, 403);
        }

        return $this->service->remove($role)
            ? $this->success('', 'SUCCESS', 204)
            : $this->warning();
    }
}
