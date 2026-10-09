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

#[AllowMockObjectsWithoutExpectations]
final class CreateUserCommandTest extends TestCase
{
    public function testExecuteFailsWhenPasswordIsEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $repo->expects(self::never())->method('findByEmail');
        $em->expects(self::never())->method('persist');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'user@example.com',
            'username' => 'tester',
            'password' => '',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Password cannot be empty.', $tester->getDisplay());
    }

    public function testExecuteFailsWhenEmailAlreadyExists(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $existing = new User();
        $existing->setEmail('existing@example.com')->setUsername('existing')->setPassword('hash');

        $repo->expects(self::once())->method('findByEmail')->willReturn($existing);
        $em->expects(self::never())->method('persist');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'existing@example.com',
            'username' => 'tester',
            'password' => 'Password123!',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Email already exists', $tester->getDisplay());
    }

    public function testExecuteFailsWhenPhoneAlreadyExists(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $repo->method('findByEmail')->willReturn(null);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByPhone')->willReturn(new User());
        $em->expects(self::never())->method('persist');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => 'new@example.com',
            'username' => 'newuser',
            'password' => 'Password123!',
            '--phone' => '+8613912345678',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Phone already exists', $tester->getDisplay());
    }

    public function testExecuteCreatesUserWithNormalizedFieldsAndRoles(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $repo = $this->createMock(UserRepository::class);

        $repo->method('findByEmail')->willReturn(null);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByPhone')->willReturn(null);

        $persistedUser = null;
        $em->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (User $user) use (&$persistedUser): bool {
                $persistedUser = $user;

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(User::class), 'PlainPass123!')
            ->willReturn('hashed-value');

        $command = new CreateUserCommand($em, $hasher, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'email' => '  NewUser@Example.COM ',
            'username' => ' NewUser ',
            'password' => 'PlainPass123!',
            '--phone' => '+8613912345678',
            '--phone-verified' => true,
            '--role' => ['editor', 'ROLE_REPORT,manager'],
            '--admin' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($persistedUser);

        self::assertSame('newuser@example.com', $persistedUser->getEmail());
        self::assertSame('newuser', $persistedUser->getUsername());
        self::assertSame('+8613912345678', $persistedUser->getPhone());
        self::assertTrue($persistedUser->isPhoneVerified());
        self::assertSame('hashed-value', $persistedUser->getPassword());
        self::assertContains('ROLE_EDITOR', $persistedUser->getRoles());
        self::assertContains('ROLE_REPORT', $persistedUser->getRoles());
        self::assertContains('ROLE_MANAGER', $persistedUser->getRoles());
        self::assertContains('ROLE_ADMIN', $persistedUser->getRoles());
    }
}
