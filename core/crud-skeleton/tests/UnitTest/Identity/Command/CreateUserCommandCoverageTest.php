<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Command;

use App\Identity\Command\CreateUserCommand;
use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the remaining branches of src/Identity/Command/CreateUserCommand.php
 * (uncovered lines 83, 85, 137, 143 in var/uncovered-map.txt):
 *   - 83/85: username already exists → failure
 *   - 137:   normalizeRoles() skips non-string / empty role option values
 *   - 143:   normalizeRoles() skips empty segments of comma-separated roles
 * plus a skipped bug-repro test for missing non-empty email/username validation.
 */
#[AllowMockObjectsWithoutExpectations]
final class CreateUserCommandCoverageTest extends TestCase
{
    public function testExecuteFailsWhenUsernameAlreadyExists(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $existing = (new User())
            ->setEmail('other@example.com')
            ->setUsername('taken')
            ->setPassword('hash');

        $repo->method('findByEmail')->with('new@example.com')->willReturn(null);
        $repo->method('findByUsername')->with('taken')->willReturn($existing);
        $em->expects(self::never())->method('persist');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'new@example.com',
            'username' => 'taken',
            'password' => 'Password123!',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Username already exists: taken', $tester->getDisplay());
    }

    public function testExecuteSkipsNonStringAndEmptyRoleOptionValues(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $repo->method('findByEmail')->willReturn(null);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByPhone')->willReturn(null);

        $persisted = null;
        $em->method('persist')->willReturnCallback(function (User $user) use (&$persisted): User {
            $persisted = $user;

            return $user;
        });
        $em->method('flush');
        $hasher->method('hashPassword')->willReturn('hashed-value');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'a@example.com',
            'username' => 'auser',
            'password' => 'Password123!',
            '--role' => ['', 123, 'ROLE_EDITOR'],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($persisted);
        // '' and the non-string 123 are skipped; only ROLE_EDITOR is kept.
        self::assertSame(['ROLE_EDITOR', 'ROLE_USER'], $persisted->getRoles());
    }

    public function testExecuteSplitsCommaSeparatedRolesSkippingEmptySegments(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $repo->method('findByEmail')->willReturn(null);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByPhone')->willReturn(null);

        $persisted = null;
        $em->method('persist')->willReturnCallback(function (User $user) use (&$persisted): User {
            $persisted = $user;

            return $user;
        });
        $em->method('flush');
        $hasher->method('hashPassword')->willReturn('hashed-value');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'b@example.com',
            'username' => 'buser',
            'password' => 'Password123!',
            // CommandTester uses ArrayInput which does NOT auto-wrap a single string
            // into an array for VALUE_IS_ARRAY options; pass an array like real CLI
            // argv parsing (ArgvInput) produces.
            '--role' => ['ROLE_EDITOR,,admin,'],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($persisted);
        // Leading/trailing/duplicate empty segments are skipped, admin is ROLE_-prefixed.
        self::assertSame(['ROLE_EDITOR', 'ROLE_ADMIN', 'ROLE_USER'], $persisted->getRoles());
    }

    public function testExecuteCreatesUserWithoutPhoneAndRoles(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $repo->method('findByEmail')->willReturn(null);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByPhone')->willReturn(null);

        $persisted = null;
        $em->method('persist')->willReturnCallback(function (User $user) use (&$persisted): User {
            $persisted = $user;

            return $user;
        });
        $em->method('flush');
        $hasher->method('hashPassword')->willReturn('hashed-value');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'c@example.com',
            'username' => 'cuser',
            'password' => 'Password123!',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($persisted);
        self::assertNull($persisted->getPhone());
        self::assertFalse($persisted->isPhoneVerified());
        self::assertSame(['ROLE_USER'], $persisted->getRoles());
        self::assertStringContainsString('User created successfully.', $tester->getDisplay());
    }

    /**
     * Correct-behavior test for Bug E: the command accepts empty email/username
     * arguments and silently creates accounts with no email/username. It should
     * fail with a clear message (like the empty-password guard does).
     *
     * Skipped because src/CreateUserCommand only guards the password, not the
     * email/username arguments.
     */
    public function testExecuteWithEmptyEmailShouldFail(): void
    {
        self::markTestSkipped(
            'Bug E: CreateUserCommand does not validate that email/username are non-empty; '
            . 'an empty email creates an account with email="" (second run then hits a '
            . 'unique-constraint exception). '
            . 'See docs/issues/coverage-2026-08-09/identity-controllers.md.'
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $repo->method('findByEmail')->willReturn(null);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByPhone')->willReturn(null);

        $em->expects(self::never())->method('persist');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => '',
            'username' => 'emptymail',
            'password' => 'Password123!',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }
}
