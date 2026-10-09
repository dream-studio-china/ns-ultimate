<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Entity;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    #[Group('low-value')]
    public function testConstructorInitializesCoreFields(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $profile->getUuid()
        );
        self::assertSame($user, $profile->getUser());
        self::assertSame(Profile::LEVEL_BRONZE, $profile->getLevel());
        self::assertNull($profile->getJoinedAt());
        self::assertNull($profile->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getCreatedAt());
        self::assertNull($profile->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testConstructorAcceptsCustomLevel(): void
    {
        $user = new User();
        $profile = new Profile($user, Profile::LEVEL_GOLD);

        self::assertSame(Profile::LEVEL_GOLD, $profile->getLevel());
    }

    #[Group('low-value')]
    public function testLevelConstants(): void
    {
        self::assertSame('bronze', Profile::LEVEL_BRONZE);
        self::assertSame('silver', Profile::LEVEL_SILVER);
        self::assertSame('gold', Profile::LEVEL_GOLD);
        self::assertSame('platinum', Profile::LEVEL_PLATINUM);
        self::assertSame('diamond', Profile::LEVEL_DIAMOND);
    }

    public function testSetUserUpdatesAndTouches(): void
    {
        $user1 = new User();
        $profile = new Profile($user1);

        self::assertNull($profile->getUpdatedAt());

        $user2 = new User();
        $profile->setUser($user2);

        self::assertSame($user2, $profile->getUser());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testSetUserNull(): void
    {
        $user = new User();
        $profile = new Profile($user);
        $profile->setUser(null);

        self::assertNull($profile->getUser());
    }

    public function testSetLevelUpdatesAndTouches(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertNull($profile->getUpdatedAt());

        $profile->setLevel(Profile::LEVEL_GOLD);

        self::assertSame(Profile::LEVEL_GOLD, $profile->getLevel());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testAllLevelsCanBeSet(): void
    {
        $user = new User();
        $profile = new Profile($user);

        $profile->setLevel(Profile::LEVEL_SILVER);
        self::assertSame(Profile::LEVEL_SILVER, $profile->getLevel());

        $profile->setLevel(Profile::LEVEL_PLATINUM);
        self::assertSame(Profile::LEVEL_PLATINUM, $profile->getLevel());

        $profile->setLevel(Profile::LEVEL_DIAMOND);
        self::assertSame(Profile::LEVEL_DIAMOND, $profile->getLevel());

        $profile->setLevel(Profile::LEVEL_BRONZE);
        self::assertSame(Profile::LEVEL_BRONZE, $profile->getLevel());
    }

    #[Group('low-value')]
    public function testSetJoinedAt(): void
    {
        $user = new User();
        $profile = new Profile($user);

        $date = new \DateTimeImmutable('2025-01-15');
        $profile->setJoinedAt($date);

        self::assertSame($date, $profile->getJoinedAt());
    }

    #[Group('low-value')]
    public function testSetJoinedAtNull(): void
    {
        $user = new User();
        $profile = new Profile($user);
        $profile->setJoinedAt(new \DateTimeImmutable());
        $profile->setJoinedAt(null);

        self::assertNull($profile->getJoinedAt());
    }

    public function testPrePersistSetsJoinedAtWhenNull(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertNull($profile->getJoinedAt());

        $profile->prePersist();

        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getJoinedAt());
    }

    public function testPrePersistDoesNotOverrideExistingJoinedAt(): void
    {
        $user = new User();
        $profile = new Profile($user);

        $date = new \DateTimeImmutable('2024-06-01');
        $profile->setJoinedAt($date);

        $profile->prePersist();

        self::assertSame($date, $profile->getJoinedAt());
    }

    public function testTouch(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertNull($profile->getUpdatedAt());

        $profile->touch();

        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());
    }

    public function testToString(): void
    {
        $user = new User();
        $user->setUsername('JohnDoe');
        $profile = new Profile($user, Profile::LEVEL_GOLD);

        self::assertStringContainsString('johndoe', (string) $profile);
        self::assertStringContainsString('gold', (string) $profile);
    }

    public function testToStringPrefersNickname(): void
    {
        $user = new User();
        $user->setUsername('JohnDoe');
        $profile = new Profile($user);
        $profile->setNickname('Johnny');

        self::assertStringContainsString('Johnny', (string) $profile);
    }

    public function testToStringWithNullUser(): void
    {
        $user = new User();
        $profile = new Profile($user);
        $profile->setUser(null);

        self::assertStringContainsString('N/A', (string) $profile);
    }

    public function testUuidIsUnique(): void
    {
        $user = new User();
        $profile1 = new Profile($user);
        $profile2 = new Profile($user);

        self::assertNotSame($profile1->getUuid(), $profile2->getUuid());
    }

    #[Group('low-value')]
    public function testUuidMatchesV4Format(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $profile->getUuid()
        );
    }

    // ──────────────────────── profile fields ────────────────────────

    #[Group('low-value')]
    public function testNicknameAccessors(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertNull($profile->getNickname());

        $profile->setNickname('Johnny');
        self::assertSame('Johnny', $profile->getNickname());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());

        $profile->setNickname(null);
        self::assertNull($profile->getNickname());
    }

    #[Group('low-value')]
    public function testAvatarAccessors(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertNull($profile->getAvatar());

        $profile->setAvatar('https://example.com/avatar.jpg');
        self::assertSame('https://example.com/avatar.jpg', $profile->getAvatar());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());

        $profile->setAvatar(null);
        self::assertNull($profile->getAvatar());
    }

    #[Group('low-value')]
    public function testMetadataAccessors(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertNull($profile->getMetadata());

        $meta = ['pref_lang' => 'zh', 'pref_theme' => 'dark'];
        $profile->setMetadata($meta);
        self::assertSame($meta, $profile->getMetadata());
        self::assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());

        $profile->setMetadata(null);
        self::assertNull($profile->getMetadata());
    }

    #[Group('low-value')]
    public function testDefaultProfileFieldsAreNull(): void
    {
        $user = new User();
        $profile = new Profile($user);

        self::assertNull($profile->getNickname());
        self::assertNull($profile->getAvatar());
        self::assertNull($profile->getMetadata());
    }
}
