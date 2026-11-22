<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Command;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Accounting\Service\LedgerAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:accounting:seed-authorization', description: 'Register general ledger and store cash roles without assigning them')]
final class SeedLedgerAuthorizationCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->em->wrapInTransaction(function (): void {
            $roles = $this->em->getRepository(Role::class);
            $permissions = $this->em->getRepository(Permission::class);
            $byAction = [];
            $grants = [];
            foreach (LedgerAccess::ROLES as $code => $actions) {
                $grants[$code] = [Role::SCOPE_GLOBAL, array_map(static fn ($action) => 'accounting:ledger:'.$action, $actions)];
            }
            $grants['store_cash_reader'] = [Role::SCOPE_STORE, ['store:cash_entry:read']];
            $grants['store_cash_recorder'] = [Role::SCOPE_STORE, ['store:cash_entry:read', 'store:cash_entry:create']];
            $grants['store_accounting_reader'] = [Role::SCOPE_STORE, ['store:accounting:read']];
            foreach ($grants as $code => [$scope, $permissionCodes]) {
                $role = $roles->findOneBy(['code' => $code]);
                if ($role !== null && $role->getScopeType() !== $scope) {
                    throw new \LogicException('An existing Finance role has an incompatible scope: '.$code);
                }
                if ($role === null) {
                    $role = (new Role($code, ucwords(str_replace('_', ' ', $code)), $scope))->setIsSystem(true);
                    $this->em->persist($role);
                }
                foreach ($permissionCodes as $permissionCode) {
                    if (!isset($byAction[$permissionCode])) {
                        $permission = $permissions->findOneBy(['code' => $permissionCode]);
                        if ($permission === null) {
                            [$domain, $resource, $action] = explode(':', $permissionCode);
                            $permission = (new Permission($permissionCode, $domain, $resource, $action, $permissionCode))->setIsSystem(true);
                            $this->em->persist($permission);
                        }
                        $byAction[$permissionCode] = $permission;
                    }
                    $role->addPermission($byAction[$permissionCode]);
                }
            }
        });
        $output->writeln('Finance roles registered. No assignments or financial records were created.');

        return Command::SUCCESS;
    }
}
