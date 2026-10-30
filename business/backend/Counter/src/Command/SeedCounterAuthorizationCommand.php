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

/**
 * Registers counter configuration and counter-session capabilities as separate store-scoped grants.
 * Existing counter managers keep configuration rights only; session and order grants are opt-in.
 */
#[AsCommand(name: 'app:counter:seed-authorization', description: 'Register counter configuration, session and counter-order capability roles')]
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
        ['store:counter_session:read', 'counter_session', 'read', 'Read store counter service sessions'],
        ['store:counter_session:update', 'counter_session', 'update', 'Update counter session participants and notes'],
        ['store:counter_session:end', 'counter_session', 'end', 'End closed counter service sessions'],
        ['store:counter_order:read', 'counter_order', 'read', 'Read orders associated with counter sessions'],
        ['store:counter_order:create', 'counter_order', 'create', 'Create orders in an active counter session'],
        ['store:counter_order:replace', 'counter_order', 'replace', 'Replace eligible counter session orders'],
    ];

    private const ROLES = [
        // Existing configuration role keeps its original grants.
        'store_counter_manager' => [
            'store:counter:read', 'store:counter:create', 'store:counter:update', 'store:counter:delete',
            'store:order_counter:read', 'store:order_counter:create', 'store:order_counter:delete',
        ],
        'store_counter_session_operator' => [
            'store:counter_session:read', 'store:counter_session:update', 'store:counter_session:end',
            'store:counter_order:read', 'store:counter_order:create',
        ],
        'store_counter_order_editor' => [
            'store:counter_session:read', 'store:counter_order:read', 'store:counter_order:replace',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (self::ROLES as $code => $grants) {
            $role = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => $code]);
            if ($role instanceof Role && $role->getScopeType() !== Role::SCOPE_STORE) {
                $output->writeln('<error>Role '.$code.' exists with an incompatible scope.</error>');
                return Command::FAILURE;
            }
        }
        $permissionRepository = $this->entityManager->getRepository(Permission::class);
        $permissions = [];
        foreach (self::PERMISSIONS as [$code, $resource, $action, $name]) {
            $permission = $permissionRepository->findOneBy(['code' => $code]);
            if (!$permission instanceof Permission) {
                $permission = new Permission($code, 'store', $resource, $action, $name);
                $permission->setIsSystem(true);
                $this->entityManager->persist($permission);
            }
            $permissions[$code] = $permission;
        }

        $roleRepository = $this->entityManager->getRepository(Role::class);
        foreach (self::ROLES as $code => $grants) {
            $role = $roleRepository->findOneBy(['code' => $code]);
            if (!$role instanceof Role) {
                $role = new Role($code, ucwords(str_replace('_', ' ', $code)), Role::SCOPE_STORE);
                $role->setIsSystem(true);
                $this->entityManager->persist($role);
            }
            foreach ($grants as $grant) {
                if (!isset($permissions[$grant])) throw new \LogicException('A required counter permission was not registered.');
                $role->addPermission($permissions[$grant]);
            }
            $role->touch();
        }
        $this->entityManager->flush();

        $output->writeln('<info>Counter configuration, session and counter-order roles are ready. No user assignments were created.</info>');
        return Command::SUCCESS;
    }
}
