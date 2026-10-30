<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Command;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:store:seed-staff-authorization', description: 'Register staff operational roles without assigning them to any user')]
final class SeedStaffAuthorizationCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $roles = [
            'store_availability_operator' => ['store:product:read', 'store:product:availability'],
            'store_order_reader' => ['store:order:read'],
            'store_order_operator' => ['store:order:read', 'store:order:accept', 'store:order:reject', 'store:order:fulfill', 'store:order:verify'],
            'store_catalog_operator' => ['store:catalog_image:create', 'store:product:availability'],
            'store_operation_auditor' => ['store:operation:read'],
        ];
        foreach (['product', 'product_category', 'specification'] as $resource) {
            foreach (['read', 'create', 'update', 'delete'] as $action) $roles['store_catalog_operator'][] = "store:$resource:$action";
        }
        // Validate existing role scope before writing any seed data.
        foreach ($roles as $code => $codes) {
            $role = $this->em->getRepository(Role::class)->findOneBy(['code' => $code]);
            if ($role instanceof Role && $role->getScopeType() !== Role::SCOPE_STORE) {
                $output->writeln('<error>Existing staff role has an incompatible scope.</error>');
                return Command::FAILURE;
            }
        }
        $permissions = [];
        foreach (array_unique(array_merge(...array_values($roles))) as $code) {
            $permission = $this->em->getRepository(Permission::class)->findOneBy(['code' => $code]);
            if (!$permission instanceof Permission) {
                [, $resource, $action] = explode(':', $code);
                $permission = (new Permission($code, 'store', $resource, $action, $code))->setIsSystem(true);
                $this->em->persist($permission);
            }
            $permissions[$code] = $permission;
        }
        foreach ($roles as $code => $codes) {
            $role = $this->em->getRepository(Role::class)->findOneBy(['code' => $code]);
            if (!$role instanceof Role) {
                $role = (new Role($code, ucwords(str_replace('_', ' ', $code)), Role::SCOPE_STORE))->setIsSystem(true);
                $this->em->persist($role);
            }
            foreach ($codes as $permission) {
                if (!isset($permissions[$permission])) throw new \LogicException('A required staff permission was not registered.');
                $role->addPermission($permissions[$permission]);
            }
            $role->touch();
        }
        $this->em->flush();
        $output->writeln('<info>Staff roles registered. No memberships or assignments were created.</info>');
        return Command::SUCCESS;
    }
}
