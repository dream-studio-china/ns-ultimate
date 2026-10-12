<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Command;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:wechat:seed-authorization', description: 'Seed WeChat permissions and the store WeChat manager role')]
final class SeedWechatAuthorizationCommand extends Command
{
    private const PERMISSIONS = [
        ['store:wechat:read', 'wechat', 'read', 'Read the store WeChat configuration'],
        ['store:wechat:create', 'wechat', 'create', 'Create the store WeChat configuration'],
        ['store:wechat:update', 'wechat', 'update', 'Update the store WeChat configuration'],
        ['store:wechat:refund', 'wechat', 'refund', 'Request store WeChat refunds'],
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
        $role = $roleRepository->findOneBy(['code' => 'store_wechat_manager']);
        if (!$role instanceof Role) {
            $role = new Role('store_wechat_manager', 'Store WeChat Manager', Role::SCOPE_STORE);
            $role->setIsSystem(true);
            $this->entityManager->persist($role);
        } elseif ($role->getScopeType() !== Role::SCOPE_STORE) {
            $output->writeln('<error>Role store_wechat_manager exists with an incompatible scope.</error>');
            return Command::FAILURE;
        }

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $role->touch();
        $this->entityManager->flush();

        $output->writeln('<info>WeChat permissions and store_wechat_manager role are ready.</info>');
        return Command::SUCCESS;
    }
}
