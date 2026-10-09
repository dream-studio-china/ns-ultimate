<?php

declare(strict_types=1);

namespace App\Identity\Command;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:identity:user:create',
    description: 'Create a user from CLI and optionally assign roles (e.g. ROLE_ADMIN).'
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email (unique)')
            ->addArgument('username', InputArgument::REQUIRED, 'Username (unique)')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain password')
            ->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Phone number (unique, optional)')
            ->addOption('role', 'R', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role to assign. Repeatable and supports comma-separated values, e.g. --role=ROLE_EDITOR --role=ROLE_ADMIN or --role=ROLE_EDITOR,ROLE_ADMIN')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Shortcut to add ROLE_ADMIN')
            ->addOption('phone-verified', null, InputOption::VALUE_NONE, 'Mark phone as verified (default: false)')
            ->setHelp(<<<'HELP'
Create a user from CLI.

Examples:
  php bin/console app:identity:user:create admin@example.com admin 'AdminPass123!' --admin
  php bin/console app:identity:user:create editor@example.com editor 'EditorPass123!' --role=ROLE_EDITOR
  php bin/console app:identity:user:create ops@example.com ops 'OpsPass123!' --role=ROLE_OPERATOR,ROLE_REPORT
  php bin/console app:identity:user:create user@example.com user 'UserPass123!' --phone=13912345678 --phone-verified

Notes:
  - Roles are normalized to upper-case and auto-prefixed with ROLE_.
  - ROLE_USER is granted automatically by the User entity and does not need to be set manually.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $username = mb_strtolower(trim((string) $input->getArgument('username')));
        $plainPassword = (string) $input->getArgument('password');
        $phoneInput = $input->getOption('phone');
        $phone = is_string($phoneInput) ? trim($phoneInput) : null;
        $phone = $phone === '' ? null : $phone;

        if ($plainPassword === '') {
            $io->error('Password cannot be empty.');

            return Command::FAILURE;
        }

        if ($this->userRepository->findByEmail($email) !== null) {
            $io->error(sprintf('Email already exists: %s', $email));

            return Command::FAILURE;
        }

        if ($this->userRepository->findByUsername($username) !== null) {
            $io->error(sprintf('Username already exists: %s', $username));

            return Command::FAILURE;
        }

        if ($phone !== null && $this->userRepository->findByPhone($phone) !== null) {
            $io->error(sprintf('Phone already exists: %s', $phone));

            return Command::FAILURE;
        }

        $rolesOption = $input->getOption('role');
        $roleInputs = is_array($rolesOption) ? $rolesOption : [];
        $roles = $this->normalizeRoles($roleInputs, (bool) $input->getOption('admin'));

        $user = (new User())
            ->setEmail($email)
            ->setUsername($username)
            ->setPhone($phone)
            ->setPhoneVerified((bool) $input->getOption('phone-verified'))
            ->setRoles($roles);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('User created successfully.');
        $io->table(
            ['id', 'email', 'username', 'phone', 'roles'],
            [[
                (string) $user->getId(),
                $user->getEmail(),
                $user->getUsername(),
                $user->getPhone() ?? '-',
                implode(', ', $user->getRoles()),
            ]]
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<int, mixed> $roleInputs
     *
     * @return array<int, string>
     */
    private function normalizeRoles(array $roleInputs, bool $isAdmin): array
    {
        $roles = [];

        foreach ($roleInputs as $raw) {
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            foreach (explode(',', $raw) as $candidate) {
                $role = strtoupper(trim($candidate));
                if ($role === '') {
                    continue;
                }

                if (!str_starts_with($role, 'ROLE_')) {
                    $role = 'ROLE_'.$role;
                }

                $roles[] = $role;
            }
        }

        if ($isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }

        $roles = array_values(array_unique($roles));

        return array_values(array_filter($roles, static fn (string $role): bool => $role !== 'ROLE_USER'));
    }
}
