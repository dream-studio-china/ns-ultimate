<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\Manage;

use App\Core\Controller\RestController;
use App\Store\Entity\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Status-only membership administration. The core API only lists and grants
 * memberships; these routes expose the existing suspend/revoke/activate entity
 * transitions without changing core.
 */
#[Route('/manage/stores/{storeUuid}/members/{membershipUuid}', name: 'manage-store-membership-', requirements: ['storeUuid' => '[0-9a-fA-F-]{36}', 'membershipUuid' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_ADMIN')]
final class StoreMembershipStatusController extends RestController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/suspend', name: 'suspend', methods: ['POST'])]
    public function suspend(string $storeUuid, string $membershipUuid): Response
    {
        return $this->change($storeUuid, $membershipUuid, Membership::STATUS_SUSPENDED, 'Membership suspended.');
    }

    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(string $storeUuid, string $membershipUuid): Response
    {
        return $this->change($storeUuid, $membershipUuid, Membership::STATUS_REVOKED, 'Membership revoked.');
    }

    #[Route('/activate', name: 'activate', methods: ['POST'])]
    public function activate(string $storeUuid, string $membershipUuid): Response
    {
        return $this->change($storeUuid, $membershipUuid, Membership::STATUS_ACTIVE, 'Membership activated.');
    }

    private function change(string $storeUuid, string $membershipUuid, string $status, string $message): Response
    {
        $membership = $this->em->getRepository(Membership::class)->findOneBy(['uuid' => $membershipUuid]);
        if (!$membership instanceof Membership || $membership->getStore()->getUuid() !== $storeUuid) {
            return $this->warning('Store membership not found.', 404, '', 404);
        }
        if ($membership->getStatus() === $status) {
            return $this->success($membership, $message);
        }
        // Never leave a store without an active owner through a status change.
        if (in_array($status, [Membership::STATUS_SUSPENDED, Membership::STATUS_REVOKED], true)
            && $membership->isActive()
            && $membership->getRole() === Membership::ROLE_OWNER
            && $this->em->getRepository(Membership::class)->count([
                'store' => $membership->getStore(),
                'role' => Membership::ROLE_OWNER,
                'status' => Membership::STATUS_ACTIVE,
            ]) <= 1) {
            return $this->warning('The last active owner cannot be suspended or revoked.', 409, '', 409);
        }

        $this->em->wrapInTransaction(function () use ($membership, $status): void {
            if ($status === Membership::STATUS_SUSPENDED) {
                $membership->suspend();
            } elseif ($status === Membership::STATUS_REVOKED) {
                $membership->revoke();
            } else {
                $membership->activate();
            }
            $this->em->flush();
        });

        return $this->success($membership, $message);
    }
}
