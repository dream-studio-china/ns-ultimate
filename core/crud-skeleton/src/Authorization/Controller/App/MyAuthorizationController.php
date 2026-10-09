<?php

declare(strict_types=1);

namespace App\Authorization\Controller\App;

use App\Authorization\Repository\AssignmentRepository;
use App\Authorization\Repository\RoleFieldGrantRepository;
use App\Authorization\Service\AuthorizationServiceInterface;
use App\Core\Controller\RestController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/authorization', name: 'app-authorization-')]
#[IsGranted('ROLE_USER')]
class MyAuthorizationController extends RestController
{
    public function __construct(
        private readonly AuthorizationServiceInterface $authorizationService,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly RoleFieldGrantRepository $fieldGrantRepository,
    ) {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Identity\Entity\User) {
            return $this->warning('Unauthorized', 1, null, 401);
        }

        $effective = $this->authorizationService->effectivePermissions($user);

        // Field grants: collect per permission? We need to map resource:action -> fields
        $assignments = $this->assignmentRepository->findActiveByUser($user->getUuid());
        /** @var list<int> $roleIds */
        $roleIds = [];
        foreach ($assignments as $assignment) {
            $roleId = $assignment->getRole()->getId();
            if ($roleId !== null) {
                $roleIds[] = $roleId;
            }
        }
        $roleIds = array_values(array_unique($roleIds));
        $fieldGrants = [];
        if ($roleIds !== []) {
            $grants = $this->fieldGrantRepository->findByRoleIds($roleIds);
            foreach ($grants as $grant) {
                $key = sprintf('%s:%s', $grant->getResource(), $grant->getAction());
                // Map to permission-like key for UI: use resource:action as permission? For content pilot, mapping is common:content:update etc.
                // We'll expose as resource:action
                $permissionKey = $grant->getResource().':'.$grant->getAction();
                // For pilot, fieldGrants key should be permission code like common:content:update
                // Our resource is common:content, so need to expand to permissions that grant that resource?
                // Simplify: expose fieldGrants keyed by resource:action
                if (!isset($fieldGrants[$permissionKey])) {
                    $fieldGrants[$permissionKey] = [];
                }
                foreach ($grant->getFields() as $field) {
                    if (!\in_array($field, $fieldGrants[$permissionKey], true)) {
                        $fieldGrants[$permissionKey][] = $field;
                    }
                }
            }
            foreach ($fieldGrants as &$list) {
                sort($list);
            }
            unset($list);
        }

        $data = [
            'permissions' => $effective['permissions'],
            'storeScopes' => $effective['storeScopes'],
            'fieldGrants' => $fieldGrants,
        ];

        return $this->success($data);
    }
}
