<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use App\Authorization\Entity\Role;
use App\Authorization\Repository\AssignmentRepository;
use App\Identity\Entity\User;

final class LedgerAccess
{
    public const ROLES = [
        'accounting_reader' => ['read'],
        'accounting_auditor' => ['read', 'audit'],
        'accounting_drafter' => ['read', 'draft'],
        'accounting_poster' => ['read', 'audit', 'post'],
        'accounting_reverser' => ['read', 'reverse'],
        'accounting_configurator' => ['read', 'configure'],
        'accounting_period_locker' => ['read', 'lock'],
        'accounting_period_reopener' => ['read', 'reopen'],
    ];

    public function __construct(private readonly AssignmentRepository $assignments) {}

    /** @var \WeakMap<User, list<string>> */
    private \WeakMap $cache;

    /**
     * System administrators have Finance access by default; other users need active global grants.
     *
     * Results are memoized per User instance so a single request that checks
     * capabilities more than once queries the assignment store only once. The
     * WeakMap keys on the request's User object, so it does not retain state
     * across requests in a long-running worker.
     *
     * @return list<string>
     */
    public function capabilities(User $user): array
    {
        $this->cache ??= new \WeakMap();
        if (isset($this->cache[$user])) {
            return $this->cache[$user];
        }

        return $this->cache[$user] = $this->resolve($user);
    }

    /** @return list<string> */
    private function resolve(User $user): array
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return array_values(array_unique(array_merge(...array_values(self::ROLES))));
        }

        $capabilities = [];
        foreach ($this->assignments->findActiveByUser($user->getUuid()) as $assignment) {
            if ($assignment->getScopeType() !== Role::SCOPE_GLOBAL || $assignment->getScopeUuid() !== null
                || $assignment->getRole()->getScopeType() !== Role::SCOPE_GLOBAL) {
                continue;
            }
            foreach ($assignment->getRole()->getPermissions() as $permission) {
                $code = $permission->getCode();
                if (str_starts_with($code, 'accounting:ledger:')) {
                    $capabilities[] = substr($code, strlen('accounting:ledger:'));
                }
            }
        }

        return array_values(array_unique($capabilities));
    }
}
