<?php

declare(strict_types=1);

namespace NsUltimate\Business\Counter\Command;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:counter:seed-authorization', description: 'Seed Counter permissions and the store counter manager role')]
final class SeedCounterAuthorizationCommand extends Command
{
    private const PERMISSIONS = [
        ['store:counter:read', 'counter', 'read', 'Read store counters'],
        ['store:counter:create', 'counter', 'create', 'Create store counters'],
        ['store:counter:update', 'counter', 'update', 'Update store counters'],
        ['store:counter:delete', 'counter', 'delete', 'Delete store counters'],
        ['store:order_counter:read', 'order_counter', 'read', 'Read store order counter bindings'],
        ['store:order_counter:create', 'order_counter', 'create', 'Create store order counter bindings'],
        ['store:order_counter:delete', 'order_counter', 'delete', 'Delete store order counter bindings'],
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $permissionRepository = $this->entityManager->getRepository(Permission::class);
        $permissions = [];
        foreach (self::PERMISSIONS as [$code, $resource, $action, $name]) {
            $permission = $permissionRepository->findOneBy(['code' => $code]);
            if (!$permission instanceof Permission) {
                $permission = new Permission($code, 'store', $resource, $action, $name);
                $permission->setIsSystem(true);
                $this->entityManager->persist($permission);
            }
            $permissions[] = $permission;
        }

        $roleRepository = $this->entityManager->getRepository(Role::class);
        $role = $roleRepository->findOneBy(['code' => 'store_counter_manager']);
        if (!$role instanceof Role) {
            $role = new Role('store_counter_manager', 'Store Counter Manager', Role::SCOPE_STORE);
            $role->setIsSystem(true);
            $this->entityManager->persist($role);
        } elseif ($role->getScopeType() !== Role::SCOPE_STORE) {
            $output->writeln('<error>Role store_counter_manager exists with an incompatible scope.</error>');
            return Command::FAILURE;
        }

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $role->touch();
        $this->entityManager->flush();

        $output->writeln('<info>Counter permissions and store_counter_manager role are ready.</info>');
        return Command::SUCCESS;
    }
}
