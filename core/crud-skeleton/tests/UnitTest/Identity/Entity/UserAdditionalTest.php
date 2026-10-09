<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Entity;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Complements UserTest with the remaining uncovered branch: setProfile() and
 * its owning-side back-reference synchronization (User.php lines 153-160).
 */
final class UserAdditionalTest extends TestCase
{
    public function testSetProfileNullKeepsProfileNull(): void
    {
        $user = new User();
        $result = $user->setProfile(null);

        self::assertSame($user, $result);
        self::assertNull($user->getProfile());
    }

    public function testSetProfileLinksBackWhenUserMismatch(): void
    {
        $user = new User();
        $profile = new Profile(new User()); // owned by a *different* user

        $user->setProfile($profile);

        self::assertSame($profile, $user->getProfile());
        self::assertSame($user, $profile->getUser());
    }

    public function testSetProfileDoesNotRewriteWhenAlreadySameUser(): void
    {
        $user = new User();
        $profile = new Profile($user); // already owned by $user

        $user->setProfile($profile);

        self::assertSame($profile, $user->getProfile());
        self::assertSame($user, $profile->getUser());
    }

    public function testSetProfileReplacesPreviousProfile(): void
    {
        $user = new User();
        $first = new Profile($user);
        $second = new Profile(new User());

        $user->setProfile($first);
        $user->setProfile($second);

        self::assertSame($second, $user->getProfile());
        self::assertSame($user, $second->getUser());
        // The previous profile is no longer referenced by the user.
        self::assertNotSame($first, $user->getProfile());
    }

    public function testSetProfileBackReferenceIsIdempotent(): void
    {
        $user = new User();
        $profile = new Profile($user);

        $user->setProfile($profile);
        $user->setProfile($profile);

        self::assertSame($user, $profile->getUser());
        self::assertSame($profile, $user->getProfile());
    }
}
