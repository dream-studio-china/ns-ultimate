<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Command;

use App\Authorization\Entity\Permission;
use App\Authorization\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:huifu:seed-authorization', description: 'Seed Huifu Store permissions and the Store Huifu manager role')]
final class SeedHuifuAuthorizationCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $role = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => 'store_huifu_manager']);
        if (!$role instanceof Role) {
            $role = new Role('store_huifu_manager', 'Store Huifu Manager', Role::SCOPE_STORE);
            $role->setIsSystem(true);
            $this->entityManager->persist($role);
        } elseif ($role->getScopeType() !== Role::SCOPE_STORE) {
            $output->writeln('<error>Role store_huifu_manager exists with an incompatible scope.</error>');
            return Command::FAILURE;
        }

        foreach ([
            'setting' => ['read', 'create', 'update', 'delete'],
            'payment' => ['read', 'create'],
            'refund' => ['read', 'create'],
            'pos_receipt' => ['read'],
        ] as $resource => $actions) {
            foreach ($actions as $action) {
                $code = sprintf('store:huifu_%s:%s', $resource, $action);
                $permission = $this->entityManager->getRepository(Permission::class)->findOneBy(['code' => $code]);
                if (!$permission instanceof Permission) {
                    $permission = new Permission($code, 'store', 'huifu_'.$resource, $action, ucfirst($action).' Huifu '.str_replace('_', ' ', $resource));
                    $permission->setIsSystem(true);
                    $this->entityManager->persist($permission);
                }
                $role->addPermission($permission);
            }
        }
        $role->touch();
        $this->entityManager->flush();
        $output->writeln('<info>Huifu Store permissions and store_huifu_manager role are ready.</info>');

        return Command::SUCCESS;
    }
}
