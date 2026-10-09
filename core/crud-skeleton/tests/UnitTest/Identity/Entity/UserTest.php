<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Entity;

use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testUserImplementsRequiredInterfaces(): void
    {
        $user = new User();

        self::assertInstanceOf(\Symfony\Component\Security\Core\User\UserInterface::class, $user);
        self::assertInstanceOf(\Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface::class, $user);
    }

    public function testEmailNormalization(): void
    {
        $user = new User();
        $user->setEmail(' User@Example.COM ');

        self::assertSame('user@example.com', $user->getEmail());
        self::assertSame('user@example.com', $user->getUserIdentifier());
    }

    public function testUsernameNormalization(): void
    {
        $user = new User();
        $user->setUsername(' JohnDoe ');

        self::assertSame('johndoe', $user->getUsername());
    }

    #[Group('low-value')]
    public function testPhoneAccessors(): void
    {
        $user = new User();

        self::assertNull($user->getPhone());
        self::assertFalse($user->isPhoneVerified());

        $user->setPhone('+8613912345678');
        $user->setPhoneVerified(true);

        self::assertSame('+8613912345678', $user->getPhone());
        self::assertTrue($user->isPhoneVerified());

        $user->setPhone(null);
        self::assertNull($user->getPhone());
    }

    public function testDefaultRoleIsUser(): void
    {
        $user = new User();

        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertCount(1, $user->getRoles());
    }

    public function testCustomRolesAreMergedWithDefault(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        $roles = $user->getRoles();
        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
        self::assertCount(2, $roles);
    }

    public function testRolesAreUnique(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

        $roles = $user->getRoles();
        self::assertCount(2, $roles);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $roles);
    }

    #[Group('low-value')]
    public function testPasswordAccessors(): void
    {
        $user = new User();
        $user->setPassword('hashed_password_here');

        self::assertSame('hashed_password_here', $user->getPassword());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = new User();
        $user->setPassword('secret');

        $user->eraseCredentials();

        // Password should remain (it's the hashed version)
        self::assertSame('secret', $user->getPassword());
    }

    #[Group('low-value')]
    public function testIdDefaultsToNull(): void
    {
        $user = new User();

        self::assertNull($user->getId());
    }

    public function testUserHasUniqueImmutableUuid(): void
    {
        $first = new User();
        $second = new User();

        self::assertTrue(UUID::is_valid($first->getUuid()));
        self::assertNotSame($first->getUuid(), $second->getUuid());
        self::assertSame($first->getUuid(), $first->getUuid());
    }

    public function testToStringUsesUsernameThenEmail(): void
    {
        $user = new User();
        $user->setEmail('User@Example.COM');

        self::assertSame('user@example.com', (string) $user);

        $user->setUsername('DisplayName');

        self::assertSame('displayname', (string) $user);
    }
}
