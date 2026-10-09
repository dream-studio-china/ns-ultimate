<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Security;

use App\Identity\Entity\RefreshToken;
use App\Identity\Entity\User;
use App\Identity\Repository\RefreshTokenRepository;
use App\Identity\Security\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[AllowMockObjectsWithoutExpectations]
final class TokenManagerTest extends TestCase
{
    private const PRIVATE_KEY_PATH = __DIR__ . '/../../../Identity/Security/test_private.pem';
    private const PUBLIC_KEY_PATH = __DIR__ . '/../../../Identity/Security/test_public.pem';

    private EntityManagerInterface $em;
    private RefreshTokenRepository $refreshRepo;
    private TokenManager $tokenManager;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $cache = new ArrayAdapter();

        $this->tokenManager = new TokenManager(
            $this->em,
            $this->refreshRepo,
            $cache,
            self::PRIVATE_KEY_PATH,
            self::PUBLIC_KEY_PATH,
            null,
            7200,
            31536000,
            'test_refresh_secret',
        );
    }

    public function testCreateAndDecodeAccessToken(): void
    {
        $user = $this->createUser(42, 'testuser', 'test@example.com', ['ROLE_USER']);

        $token = $this->tokenManager->createAccessToken($user);
        self::assertNotEmpty($token);

        $payload = $this->tokenManager->decodeAccessToken($token);
        self::assertNotNull($payload);
        self::assertSame('42', $payload['sub']);
        self::assertSame('testuser', $payload['username']);
        self::assertSame('test@example.com', $payload['email']);
        self::assertContains('ROLE_USER', $payload['roles']);
        self::assertArrayHasKey('iat', $payload);
        self::assertArrayHasKey('exp', $payload);
        self::assertArrayHasKey('jti', $payload);
        self::assertSame('crud-skeleton', $payload['iss']);
    }

    public function testDecodeInvalidTokenReturnsNull(): void
    {
        self::assertNull($this->tokenManager->decodeAccessToken('invalid.token.here'));
        self::assertNull($this->tokenManager->decodeAccessToken(''));
        self::assertNull($this->tokenManager->decodeAccessToken('a.b'));
    }

    public function testDecodeTamperedTokenReturnsNull(): void
    {
        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $token = $this->tokenManager->createAccessToken($user);

        // Tamper with the payload
        $parts = explode('.', $token);
        $parts[1] = TokenManager::base64UrlEncode(json_encode(['sub' => '999']));
        $tamperedToken = implode('.', $parts);

        self::assertNull($this->tokenManager->decodeAccessToken($tamperedToken));
    }

    public function testDecodeExpiredTokenReturnsNull(): void
    {
        // Create a token manager with 0-second TTL to force immediate expiration
        $tm = new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            self::PRIVATE_KEY_PATH,
            self::PUBLIC_KEY_PATH,
            null,
            -1, // negative TTL = already expired
            31536000,
            'test_refresh_secret',
        );

        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $token = $tm->createAccessToken($user);

        self::assertNull($tm->decodeAccessToken($token));
    }

    public function testCreateRefreshTokenPersistsEntity(): void
    {
        $user = $this->createUser(1, 'testuser', 'test@example.com', []);

        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(RefreshToken::class));
        $this->em->expects(self::once())->method('flush');

        $plainToken = $this->tokenManager->createRefreshToken($user);

        self::assertNotEmpty($plainToken);
        self::assertEquals(96, strlen($plainToken)); // hex of 48 bytes = 96 chars
    }

    public function testFindValidRefreshToken(): void
    {
        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $refreshTokenEntity = new RefreshToken(
            $user,
            hash_hmac('sha256', 'my_plain_token', 'test_refresh_secret'),
            new \DateTimeImmutable('+1 year'),
            'test_jti',
        );

        $this->refreshRepo->expects(self::once())
            ->method('findValidByHash')
            ->with(hash_hmac('sha256', 'my_plain_token', 'test_refresh_secret'))
            ->willReturn($refreshTokenEntity);

        $result = $this->tokenManager->findValidRefreshToken('my_plain_token');
        self::assertNotNull($result);
        self::assertSame('test_jti', $result->getJti());
    }

    public function testRevokeRefreshToken(): void
    {
        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $tokenEntity = new RefreshToken(
            $user,
            hash_hmac('sha256', 'revoke_me', 'test_refresh_secret'),
            new \DateTimeImmutable('+1 year'),
        );
        $tokenEntity->setIdForTest(1);

        // Mock the findOneBy query on the generic repository
        $genericRepo = $this->createMock(EntityRepository::class);
        $genericRepo->expects(self::once())
            ->method('findOneBy')
            ->with(['refreshTokenHash' => hash_hmac('sha256', 'revoke_me', 'test_refresh_secret')])
            ->willReturn($tokenEntity);

        $this->em->expects(self::once())
            ->method('getRepository')
            ->with(RefreshToken::class)
            ->willReturn($genericRepo);

        $this->em->expects(self::once())->method('flush');

        $this->tokenManager->revokeRefreshToken('revoke_me');
        self::assertTrue($tokenEntity->isRevoked());
    }

    public function testRotateRefreshTokenCreatesNewAndRevokesOld(): void
    {
        $user = $this->createUser(1, 'u', 'u@e.com', ['ROLE_USER']);
        $oldHash = hash_hmac('sha256', 'old_plain_token', 'test_refresh_secret');
        $oldEntity = new RefreshToken(
            $user,
            $oldHash,
            new \DateTimeImmutable('+1 year'),
            'old_jti',
        );
        $oldEntity->setIdForTest(1);

        $genericRepo = $this->createMock(EntityRepository::class);
        $genericRepo->expects(self::once())
            ->method('findOneBy')
            ->with(['refreshTokenHash' => $oldHash])
            ->willReturn($oldEntity);

        $this->em->method('getRepository')->with(RefreshToken::class)->willReturn($genericRepo);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::exactly(2))->method('flush');

        $result = $this->tokenManager->rotateRefreshToken('old_plain_token');

        self::assertArrayHasKey('access_token', $result);
        self::assertArrayHasKey('refresh_token', $result);
        self::assertNotEmpty($result['access_token']);
        self::assertNotEmpty($result['refresh_token']);
        self::assertSame(96, strlen($result['refresh_token']));
        self::assertTrue($oldEntity->isRevoked());
    }

    public function testReuseDetectionRevokesAllTokens(): void
    {
        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $stolenHash = hash_hmac('sha256', 'stolen_token', 'test_refresh_secret');
        $stolenEntity = new RefreshToken(
            $user,
            $stolenHash,
            new \DateTimeImmutable('+1 year'),
            'stolen_jti',
        );
        $stolenEntity->setIdForTest(5);
        $stolenEntity->revoke(); // Someone already used it
        $stolenEntity->setReplacedBy(10); // And replaced it

        $genericRepo = $this->createMock(EntityRepository::class);
        $genericRepo->expects(self::once())
            ->method('findOneBy')
            ->with(['refreshTokenHash' => $stolenHash])
            ->willReturn($stolenEntity);

        $this->em->method('getRepository')->with(RefreshToken::class)->willReturn($genericRepo);

        $this->refreshRepo->expects(self::once())
            ->method('revokeAllForUser')
            ->with($user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token reuse detected');

        $this->tokenManager->rotateRefreshToken('stolen_token');
    }

    public function testBase64UrlEncodeDecode(): void
    {
        $original = 'test+data/with=padding';
        $encoded = TokenManager::base64UrlEncode($original);
        $decoded = TokenManager::base64UrlDecode($encoded);

        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
        self::assertSame($original, $decoded);
    }

    public function testAccessTokenContainsCorrectTtl(): void
    {
        $tm = new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            self::PRIVATE_KEY_PATH,
            self::PUBLIC_KEY_PATH,
            null,
            42,
            31536000,
            'secret',
        );

        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $token = $tm->createAccessToken($user);
        $payload = $tm->decodeAccessToken($token);

        self::assertNotNull($payload);
        $expectedExp = $payload['iat'] + 42;
        self::assertSame($expectedExp, $payload['exp']);
        self::assertSame(42, $tm->getAccessTtl());
    }

    public function testRevokeAccessTokenWithInvalidTokenDoesNothing(): void
    {
        $this->em->expects(self::never())->method('flush');

        $this->tokenManager->revokeAccessToken('invalid.jwt.token');
        self::assertTrue(true);
    }

    #[Group('low-value')]
    public function testDecodeAccessTokenWithWrongSegmentCountReturnsNull(): void
    {
        self::assertNull($this->tokenManager->decodeAccessToken('only.two.segments.extra'));
        self::assertNull($this->tokenManager->decodeAccessToken('one'));
    }

    #[Group('low-value')]
    public function testBase64UrlDecodeHandlesPadding(): void
    {
        $result = TokenManager::base64UrlDecode(TokenManager::base64UrlEncode('hello'));
        self::assertSame('hello', $result);

        $result = TokenManager::base64UrlDecode(TokenManager::base64UrlEncode('ab'));
        self::assertSame('ab', $result);

        $result = TokenManager::base64UrlDecode(TokenManager::base64UrlEncode('test-data'));
        self::assertSame('test-data', $result);
    }

    public function testBase64UrlDecodeReturnsFalseForInvalidInput(): void
    {
        $result = TokenManager::base64UrlDecode('!!!');
        self::assertFalse($result);
    }

    #[Group('low-value')]
    public function testDecodeAccessTokenEmptyStringReturnsNull(): void
    {
        self::assertNull($this->tokenManager->decodeAccessToken(''));
    }

    private function createUser(int $id, string $username, string $email, array $roles): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        // Use reflection to set the ID
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
