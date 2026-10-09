<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Entity;

use App\Identity\Entity\RefreshToken;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class RefreshTokenTest extends TestCase
{
    #[Group('low-value')]
    public function testConstructorAndGetters(): void
    {
        $user = $this->createUser();
        $expiresAt = new \DateTimeImmutable('+1 day');

        $token = new RefreshToken($user, 'hash123', $expiresAt, 'jti-1');

        self::assertNull($token->getId());
        self::assertSame($user, $token->getUser());
        self::assertSame('hash123', $token->getRefreshTokenHash());
        self::assertSame('jti-1', $token->getJti());
        self::assertSame($expiresAt, $token->getExpiresAt());
        self::assertNotNull($token->getCreatedAt());
        self::assertFalse($token->isRevoked());
    }

    public function testExpirationBehavior(): void
    {
        $user = $this->createUser();

        $valid = new RefreshToken($user, 'hash-valid', new \DateTimeImmutable('+5 minutes'));
        $expired = new RefreshToken($user, 'hash-expired', new \DateTimeImmutable('-1 minute'));

        self::assertFalse($valid->isExpired());
        self::assertTrue($expired->isExpired());
    }

    #[Group('low-value')]
    public function testRevokeAndMetadataMutators(): void
    {
        $token = new RefreshToken($this->createUser(), 'hash123', new \DateTimeImmutable('+1 day'));

        self::assertNull($token->getRevokedAt());
        $token->revoke();
        self::assertTrue($token->isRevoked());
        self::assertNotNull($token->getRevokedAt());

        $token->setReplacedBy(88);
        self::assertSame(88, $token->getReplacedBy());

        $token->setIpAddress('127.0.0.1');
        $token->setUserAgent('phpunit');
        self::assertSame('127.0.0.1', $token->getIpAddress());
        self::assertSame('phpunit', $token->getUserAgent());
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('refresh@example.com');
        $user->setUsername('refresh-user');
        $user->setPassword('hashed-password');

        return $user;
    }
}
