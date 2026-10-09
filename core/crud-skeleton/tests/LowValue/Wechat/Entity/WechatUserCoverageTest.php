<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Wechat\Entity;


use PHPUnit\Framework\Attributes\Group;
use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use PHPUnit\Framework\TestCase;

/**
 * Covers the remaining uncovered lines of App\Wechat\Entity\WechatUser
 * (getId and the PrePersist guard when createdAt is uninitialized).
 */
#[Group('low-value')]
final class WechatUserCoverageTest extends TestCase
{
    public function testGetIdReturnsNullBeforePersist(): void
    {
        $wu = new WechatUser(new User(), 'o_never_persisted', WechatUser::APP_TYPE_MINIAPP);

        self::assertNull($wu->getId());
    }

    public function testPrePersistInitializesCreatedAtWhenUnset(): void
    {
        // Doctrine hydrates entities without invoking the constructor, leaving
        // typed properties uninitialized. newInstanceWithoutConstructor()
        // reproduces that state without relying on the deprecated
        // ReflectionProperty::setAccessible().
        $reflection = new \ReflectionClass(WechatUser::class);
        /** @var WechatUser $wu */
        $wu = $reflection->newInstanceWithoutConstructor();

        $wu->prePersist();

        self::assertInstanceOf(\DateTimeImmutable::class, $wu->getCreatedAt());
    }

    public function testPrePersistKeepsExistingCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 00:00:00');
        $wu = new WechatUser(new User(), 'o_existing', WechatUser::APP_TYPE_OFFICIAL);

        $reflection = new \ReflectionClass(WechatUser::class);
        $property = $reflection->getProperty('createdAt');
        $property->setValue($wu, $createdAt);

        $wu->prePersist();

        self::assertSame($createdAt, $wu->getCreatedAt());
    }
}
