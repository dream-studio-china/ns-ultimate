<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Entity;

use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class WechatUserTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Group('low-value')]
    public function testConstructorSetsRequiredFields(): void
    {
        $wu = new WechatUser($this->user, 'oTest1234', WechatUser::APP_TYPE_MINIAPP);

        self::assertSame($this->user, $wu->getUser());
        self::assertSame('oTest1234', $wu->getOpenid());
        self::assertSame(WechatUser::APP_TYPE_MINIAPP, $wu->getAppType());
        self::assertNotNull($wu->getCreatedAt());
        self::assertNotNull($wu->getLastLoginAt());
        self::assertSame('WechatUser#0 miniapp oTest1234', (string) $wu);
    }

    #[Group('low-value')]
    public function testSettersAndGetters(): void
    {
        $wu = new WechatUser($this->user, 'o1', WechatUser::APP_TYPE_OFFICIAL);

        $wu->setUnionid('u_union_123');
        self::assertSame('u_union_123', $wu->getUnionid());

        $wu->setSessionKey('sk_abc');
        self::assertSame('sk_abc', $wu->getSessionKey());

        $wu->setNickname('TestUser');
        self::assertSame('TestUser', $wu->getNickname());

        $wu->setAvatar('https://example.com/avatar.jpg');
        self::assertSame('https://example.com/avatar.jpg', $wu->getAvatar());

        $wu->setSex(1);
        self::assertSame(1, $wu->getSex());

        $wu->setProvince('Guangdong');
        self::assertSame('Guangdong', $wu->getProvince());

        $wu->setCity('Shenzhen');
        self::assertSame('Shenzhen', $wu->getCity());

        $wu->setCountry('China');
        self::assertSame('China', $wu->getCountry());

        $rawData = ['openid' => 'o1', 'nickname' => 'Test'];
        $wu->setRawData($rawData);
        self::assertSame($rawData, $wu->getRawData());

        $now = new \DateTimeImmutable();
        $wu->setLastLoginAt($now);
        self::assertSame($now, $wu->getLastLoginAt());

        self::assertNotNull($wu->getUpdatedAt());
    }

    public function testOpenidCanBeUpdated(): void
    {
        $wu = new WechatUser($this->user, 'old_openid', WechatUser::APP_TYPE_MINIAPP);
        self::assertNull($wu->getUpdatedAt());

        $wu->setOpenid('new_openid');
        self::assertSame('new_openid', $wu->getOpenid());
        self::assertNotNull($wu->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testConstants(): void
    {
        self::assertSame('miniapp', WechatUser::APP_TYPE_MINIAPP);
        self::assertSame('official', WechatUser::APP_TYPE_OFFICIAL);
    }

    public function testMetadata(): void
    {
        $wu = new WechatUser($this->user, 'o_meta', WechatUser::APP_TYPE_MINIAPP);

        $meta = $wu->__metadata();
        self::assertArrayHasKey('id', $meta);
        self::assertArrayHasKey('userId', $meta);
        self::assertArrayHasKey('openid', $meta);
        self::assertArrayHasKey('appType', $meta);
        self::assertSame('o_meta', $meta['openid']);
        self::assertSame(WechatUser::APP_TYPE_MINIAPP, $meta['appType']);
    }

    #[Group('low-value')]
    public function testToStringWithNullId(): void
    {
        $wu = new WechatUser($this->user, 'o1', WechatUser::APP_TYPE_OFFICIAL);
        self::assertStringContainsString('WechatUser#0', (string) $wu);
    }
}
