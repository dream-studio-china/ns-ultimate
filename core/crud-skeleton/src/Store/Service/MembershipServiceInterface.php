<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseServiceInterface;
use App\Store\Entity\Store;
use App\Store\Entity\Membership;

/** @extends BaseServiceInterface<Membership> */
interface MembershipServiceInterface extends BaseServiceInterface
{
    public function grant(Store $store, string $userUuid, string $role): Membership;
    /** @param list<string> $allowedRoles */
    public function isAuthorized(Store $store, string $userUuid, array $allowedRoles = []): bool;
    /** @param list<string> $allowedRoles */
    public function requireAuthorization(Store $store, string $userUuid, array $allowedRoles = []): Membership;
}
