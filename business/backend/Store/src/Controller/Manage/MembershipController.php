<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\Utils\UUID;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Store\Service\MembershipServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Full membership administration for the admin UI. Status changes live in
 * StoreMembershipStatusController; this controller adds list/detail/create and
 * role updates without changing the core Store controller.
 */
#[Route('/manage/memberships', name: 'manage-memberships-')]
#[IsGranted('ROLE_ADMIN')]
final class MembershipController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin;

    public function __construct(
        protected readonly MembershipServiceInterface $service,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        try {
            $content = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->warning('Invalid JSON request.', 400, '', 400);
        }
        if (!is_array($content) || array_is_list($content)) {
            return $this->warning('Invalid membership request.', 400, '', 400);
        }

        $storeUuid = $content['storeUuid'] ?? $content['store'] ?? null;
        if (is_array($storeUuid)) {
            $storeUuid = $storeUuid['uuid'] ?? null;
        }
        if (!is_string($storeUuid) || !UUID::is_valid($storeUuid)) {
            return $this->warning('A valid store is required.', 400, '', 400);
        }
        $userUuid = $content['userUuid'] ?? null;
        if (!is_string($userUuid) || !UUID::is_valid($userUuid)) {
            return $this->warning('A valid user UUID is required.', 400, '', 400);
        }
        $role = $content['role'] ?? null;
        if (!is_string($role) || !in_array($role, Membership::roles(), true)) {
            return $this->warning('A valid membership role is required.', 400, '', 400);
        }
        $store = $this->em->getRepository(Store::class)->findOneBy(['uuid' => strtolower($storeUuid)]);
        if (!$store instanceof Store) {
            return $this->warning('Store not found.', 404, '', 404);
        }

        try {
            $membership = $this->service->grant($store, $userUuid, $role);
        } catch (\InvalidArgumentException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        }

        return $this->success($membership, 'Membership granted.', 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\\d+|[0-9a-fA-F-]{36}'])]
    public function updateAction(int|string $id, Request $request): Response
    {
        $membership = $this->service->get($this->mixIdToCommonFilter($id), false);
        if (!$membership instanceof Membership) {
            return $this->warning('Store membership not found.', 404, '', 404);
        }
        try {
            $content = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->warning('Invalid JSON request.', 400, '', 400);
        }
        if (!is_array($content) || array_is_list($content) || !array_key_exists('role', $content)) {
            return $this->warning('A membership role is required.', 400, '', 400);
        }
        $role = $content['role'];
        if (!is_string($role) || !in_array($role, Membership::roles(), true)) {
            return $this->warning('A valid membership role is required.', 400, '', 400);
        }
        if ($membership->getRole() === $role) {
            return $this->success($membership, 'Membership updated.');
        }
        if ($membership->isActive()
            && $membership->getRole() === Membership::ROLE_OWNER
            && $role !== Membership::ROLE_OWNER
            && $this->em->getRepository(Membership::class)->count([
                'store' => $membership->getStore(),
                'role' => Membership::ROLE_OWNER,
                'status' => Membership::STATUS_ACTIVE,
            ]) <= 1) {
            return $this->warning('The last active owner cannot lose the owner role.', 409, '', 409);
        }

        $membership->setRole($role);
        $this->em->flush();

        return $this->success($membership, 'Membership updated.');
    }
}
