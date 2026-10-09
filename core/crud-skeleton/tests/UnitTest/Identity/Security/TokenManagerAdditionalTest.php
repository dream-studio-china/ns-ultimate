<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Security;

use App\Identity\Entity\RefreshToken;
use App\Identity\Entity\User;
use App\Identity\Repository\RefreshTokenRepository;
use App\Identity\Security\TokenManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Complements TokenManagerTest with the remaining uncovered branches:
 * constructor error paths, malformed-but-signed token payloads, refresh-token
 * rotation edge cases (unknown token + transaction rollback) and the
 * revokeAllForUser delegation.
 *
 * Lines that are provably unreachable with valid constructor inputs
 * (50, 100-101, 121, 332) are documented in
 * docs/issues/coverage-2026-08-09/identity-security.md instead.
 */
#[AllowMockObjectsWithoutExpectations]
final class TokenManagerAdditionalTest extends TestCase
{
    private const PRIVATE_KEY_PATH = __DIR__ . '/../../../Identity/Security/test_private.pem';
    private const PUBLIC_KEY_PATH = __DIR__ . '/../../../Identity/Security/test_public.pem';

    private EntityManagerInterface $em;
    private RefreshTokenRepository $refreshRepo;
    private TokenManager $tokenManager;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->refreshRepo = $this->createMock(RefreshTokenRepository::class);

        $this->tokenManager = new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            self::PRIVATE_KEY_PATH,
            self::PUBLIC_KEY_PATH,
            null,
            7200,
            31536000,
            'test_refresh_secret',
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testCannotReadPrivateKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read private key');

        $this->silenceWarnings(fn () => new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            self::PRIVATE_KEY_PATH . '.missing',
            self::PUBLIC_KEY_PATH,
            null,
            7200,
            31536000,
            'secret',
        ));
    }

    public function testCannotLoadPrivateKeyThrows(): void
    {
        $garbage = $this->createTempFile("not-a-valid-pem\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot load private key');

        $this->silenceWarnings(fn () => new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            $garbage,
            self::PUBLIC_KEY_PATH,
            null,
            7200,
            31536000,
            'secret',
        ));
    }

    public function testCannotReadPublicKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read public key');

        $this->silenceWarnings(fn () => new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            self::PRIVATE_KEY_PATH,
            self::PUBLIC_KEY_PATH . '.missing',
            null,
            7200,
            31536000,
            'secret',
        ));
    }

    public function testCannotLoadPublicKeyThrows(): void
    {
        $garbage = $this->createTempFile("still-not-a-pem\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot load public key');

        $this->silenceWarnings(fn () => new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            self::PRIVATE_KEY_PATH,
            $garbage,
            null,
            7200,
            31536000,
            'secret',
        ));
    }

    /**
     * Documents the "dev fallback" intent: an unencrypted key with a
     * non-empty passphrase still loads, because PHP's openssl_pkey_get_private
     * ignores the passphrase for unencrypted PEMs, so the fallback branch
     * (line 50) itself is unreachable in practice (see report).
     */
    #[Group('low-value')]
    public function testUnencryptedKeyWithWrongPassphraseStillLoads(): void
    {
        $tm = new TokenManager(
            $this->em,
            $this->refreshRepo,
            new ArrayAdapter(),
            self::PRIVATE_KEY_PATH,
            self::PUBLIC_KEY_PATH,
            'wrong-passphrase',
            7200,
            31536000,
            'secret',
        );

        $user = $this->createUser(1, 'u', 'u@e.com', []);
        self::assertNotEmpty($tm->createAccessToken($user));
    }

    public function testDecodeRejectsSignedTokenWithUndecodablePayload(): void
    {
        // Signature is valid (signed with the test private key) but the
        // payload segment is not strict base64url decodable.
        $token = $this->craftSignedToken('b');
        self::assertNull($this->tokenManager->decodeAccessToken($token));
    }

    public function testDecodeRejectsSignedTokenMissingRequiredClaims(): void
    {
        $token = $this->craftSignedToken(TokenManager::base64UrlEncode(json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR)));
        self::assertNull($this->tokenManager->decodeAccessToken($token));
    }

    public function testDecodeRejectsSignedTokenWhosePayloadIsNotAnObject(): void
    {
        $token = $this->craftSignedToken(TokenManager::base64UrlEncode(json_encode('plain-string', JSON_THROW_ON_ERROR)));
        self::assertNull($this->tokenManager->decodeAccessToken($token));
    }

    public function testDecodeRejectsSignedTokenWithWrongClaimTypes(): void
    {
        $payload = [
            'sub' => 123, // must be a string
            'exp' => time() + 3600,
            'username' => 'u',
            'email' => 'u@e.com',
            'roles' => ['ROLE_USER'],
            'iat' => time(),
            'jti' => 'j',
            'iss' => 'crud-skeleton',
        ];
        $token = $this->craftSignedToken(TokenManager::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertNull($this->tokenManager->decodeAccessToken($token));
    }

    public function testDecodeRejectsSignedTokenMissingOptionalClaims(): void
    {
        // sub/exp valid but username missing -> type validation fails.
        $token = $this->craftSignedToken(TokenManager::base64UrlEncode(json_encode([
            'sub' => '1',
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR)));
        self::assertNull($this->tokenManager->decodeAccessToken($token));
    }

    public function testRotateRefreshTokenThrowsWhenTokenUnknown(): void
    {
        $genericRepo = $this->createMock(EntityRepository::class);
        $genericRepo->expects(self::once())
            ->method('findOneBy')
            ->with(['refreshTokenHash' => hash_hmac('sha256', 'ghost_token', 'test_refresh_secret')])
            ->willReturn(null);

        $this->em->method('getRepository')->with(RefreshToken::class)->willReturn($genericRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refresh token not found.');

        $this->tokenManager->rotateRefreshToken('ghost_token');
    }

    public function testRotateRefreshTokenRollsBackAndRethrowsWhenTransactionActive(): void
    {
        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $oldHash = hash_hmac('sha256', 'old_plain', 'test_refresh_secret');
        $oldEntity = new RefreshToken($user, $oldHash, new \DateTimeImmutable('+1 year'), 'old_jti');
        $oldEntity->setIdForTest(1);

        $genericRepo = $this->createMock(EntityRepository::class);
        $genericRepo->expects(self::once())
            ->method('findOneBy')
            ->with(['refreshTokenHash' => $oldHash])
            ->willReturn($oldEntity);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);

        $this->em->method('getRepository')->with(RefreshToken::class)->willReturn($genericRepo);
        $this->em->method('getConnection')->willReturn($connection);
        $this->em->expects(self::once())->method('beginTransaction');
        $this->em->expects(self::once())->method('rollback');
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())
            ->method('flush')
            ->willThrowException(new \RuntimeException('db failure'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db failure');

        $this->tokenManager->rotateRefreshToken('old_plain');
    }

    public function testRotateRefreshTokenRethrowsWithoutRollbackWhenNoActiveTransaction(): void
    {
        $user = $this->createUser(1, 'u', 'u@e.com', []);
        $oldHash = hash_hmac('sha256', 'old_plain2', 'test_refresh_secret');
        $oldEntity = new RefreshToken($user, $oldHash, new \DateTimeImmutable('+1 year'), 'old_jti2');
        $oldEntity->setIdForTest(2);

        $genericRepo = $this->createMock(EntityRepository::class);
        $genericRepo->expects(self::once())
            ->method('findOneBy')
            ->with(['refreshTokenHash' => $oldHash])
            ->willReturn($oldEntity);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(false);

        $this->em->method('getRepository')->with(RefreshToken::class)->willReturn($genericRepo);
        $this->em->method('getConnection')->willReturn($connection);
        $this->em->expects(self::once())->method('beginTransaction');
        $this->em->expects(self::never())->method('rollback');
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())
            ->method('flush')
            ->willThrowException(new \DomainException('save failed'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('save failed');

        $this->tokenManager->rotateRefreshToken('old_plain2');
    }

    #[Group('low-value')]
    public function testRevokeAllForUserDelegatesToRepository(): void
    {
        $user = $this->createUser(7, 'victim', 'victim@e.com', []);
        $this->refreshRepo->expects(self::once())->method('revokeAllForUser')->with($user);

        $this->tokenManager->revokeAllForUser($user);
    }

    private function craftSignedToken(string $payloadB64): string
    {
        $header = TokenManager::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $data = $header . '.' . $payloadB64;

        $privateKey = openssl_pkey_get_private((string) file_get_contents(self::PRIVATE_KEY_PATH));
        self::assertNotFalse($privateKey);
        $signature = '';
        $signed = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        self::assertTrue($signed);

        return $data . '.' . TokenManager::base64UrlEncode($signature);
    }

    private function createTempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/tokentest_' . uniqid('', true) . '.pem';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function silenceWarnings(callable $fn): void
    {
        set_error_handler(static fn (int $severity, string $message, string $file, int $line): bool => true);
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
    }

    /** @param list<string> $roles */
    private function createUser(int $id, string $username, string $email, array $roles): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword('hashed_password');

        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
