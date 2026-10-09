<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\Entity\RefreshToken;
use App\Identity\Entity\User;
use App\Identity\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @phpstan-type AccessTokenPayload array{
 *     sub: string,
 *     username: string,
 *     email: string,
 *     roles: list<string>,
 *     iat: int,
 *     exp: int,
 *     jti: string,
 *     iss: string
 * }
 */
class TokenManager
{
    private \OpenSSLAsymmetricKey $privateKey;
    private \OpenSSLAsymmetricKey $publicKey;
    private string $refreshSecret;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenRepository $refreshRepo,
        private readonly CacheItemPoolInterface $cache,
        string $privateKeyPath,
        string $publicKeyPath,
        ?string $passphrase,
        private readonly int $accessTtl,
        private readonly int $refreshTtl,
        string $refreshSecret,
    ) {
        $privateKeyPem = file_get_contents($privateKeyPath);
        if ($privateKeyPem === false) {
            throw new \RuntimeException("Cannot read private key: {$privateKeyPath}");
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($privateKey === false && $passphrase !== null && $passphrase !== '') {
            // Dev fallback: allow unencrypted key even when passphrase is configured.
            $privateKey = openssl_pkey_get_private($privateKeyPem);
        }

        if ($privateKey === false) {
            throw new \RuntimeException('Cannot load private key. Check key path/passphrase.');
        }

        $pubKeyRaw = file_get_contents($publicKeyPath);
        if ($pubKeyRaw === false) {
            throw new \RuntimeException("Cannot read public key: {$publicKeyPath}");
        }

        $publicKey = openssl_pkey_get_public($pubKeyRaw);
        if ($publicKey === false) {
            throw new \RuntimeException('Cannot load public key. Check public key content.');
        }

        $this->privateKey = $privateKey;
        $this->publicKey = $publicKey;
        $this->refreshSecret = $refreshSecret;
    }

    /**
     * Create a signed RS256 JWT access token.
     */
    public function createAccessToken(User $user): string
    {
        $now = time();
        $jti = bin2hex(random_bytes(16));

        $header = self::base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $payload = self::base64UrlEncode(json_encode([
            'sub' => (string) $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'iat' => $now,
            'exp' => $now + $this->accessTtl,
            'jti' => $jti,
            'iss' => 'crud-skeleton',
        ], JSON_THROW_ON_ERROR));

        $data = "{$header}.{$payload}";
        $signature = '';
        $signed = openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        if ($signed !== true) {
            $detail = openssl_error_string() ?: 'unknown openssl error';
            throw new \RuntimeException('Failed to sign JWT: ' . $detail);
        }

        return "{$data}." . self::base64UrlEncode($signature);
    }

    /**
     * Decode and verify a JWT access token. Returns payload or null on failure.
     */
    /** @return AccessTokenPayload|null */
    public function decodeAccessToken(string $token): ?array
    {
        $payload = $this->decodeAccessTokenWithoutBlacklist($token);
        if ($payload === null) {
            return null;
        }

        // Check if token is revoked (blacklisted)
        $jti = $payload['jti'] ?? null;
        if ($jti !== null && $this->isAccessTokenRevoked($jti)) {
            return null;
        }

        return $payload;
    }

    /**
     * Decode and verify a JWT access token without checking the revocation blacklist.
     * Used internally for revocation operations.
     */
    /** @return AccessTokenPayload|null */
    private function decodeAccessTokenWithoutBlacklist(string $token): ?array
    {
        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $data = "{$headerB64}.{$payloadB64}";
        $signature = self::base64UrlDecode($signatureB64);

        if (\is_bool($signature)) {
            return null;
        }

        $result = openssl_verify($data, $signature, $this->publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            return null;
        }

        $payloadRaw = self::base64UrlDecode($payloadB64);
        if (\is_bool($payloadRaw)) {
            return null;
        }

        $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($payload) || !isset($payload['exp'], $payload['sub'])) {
            return null;
        }

        if (
            !is_string($payload['sub']) ||
            !is_string($payload['username'] ?? null) ||
            !is_string($payload['email'] ?? null) ||
            !is_array($payload['roles'] ?? null) ||
            !is_int($payload['iat'] ?? null) ||
            !is_int($payload['exp']) ||
            !is_string($payload['jti'] ?? null) ||
            !is_string($payload['iss'] ?? null)
        ) {
            return null;
        }

        /** @var AccessTokenPayload $payload */

        // Check expiration
        if ($payload['exp'] < time()) {
            return null;
        }

        // Check if token is revoked (blacklisted)
        $jti = $payload['jti'] ?? null;
        if ($jti !== null && $this->isAccessTokenRevoked($jti)) {
            return null;
        }

        return $payload;
    }

    /**
     * Check if an access token JTI is in the revocation blacklist.
     */
    private function isAccessTokenRevoked(string $jti): bool
    {
        // Create a cache-safe key by hashing the jti (which is hex, but we hash for consistency)
        $cacheKey = 'revoked_token_' . hash('xxh64', $jti);
        return $this->cache->hasItem($cacheKey);
    }

    /**
     * Create and persist a refresh token. Returns the plaintext token to give to the client.
     */
    public function createRefreshToken(User $user): string
    {
        $plain = bin2hex(random_bytes(48));
        $hash = $this->hashRefreshToken($plain);
        $jti = bin2hex(random_bytes(16));
        $expiresAt = (new \DateTimeImmutable())->setTimestamp(time() + $this->refreshTtl);

        $entity = new RefreshToken($user, $hash, $expiresAt, $jti);
        $this->em->persist($entity);
        $this->em->flush();

        return $plain;
    }

    /**
     * Find a valid (non-revoked, non-expired) RefreshToken entity for a given plaintext token.
     */
    public function findValidRefreshToken(string $plainToken): ?RefreshToken
    {
        $hash = $this->hashRefreshToken($plainToken);

        return $this->refreshRepo->findValidByHash($hash);
    }

    /**
     * Rotate a refresh token: revokes the old one and creates a new one.
     * Returns ['access_token' => string, 'refresh_token' => string] or throws.
     *
     * Implements reuse detection: if a revoked/replaced token is presented, all user tokens are revoked.
     * @return array<string, string>
     */
    public function rotateRefreshToken(string $oldPlainToken): array
    {
        $hash = $this->hashRefreshToken($oldPlainToken);

        // Also check for replaced tokens (reuse detection)
        $old = $this->em->getRepository(RefreshToken::class)->findOneBy([
            'refreshTokenHash' => $hash,
        ]);

        if ($old === null) {
            throw new \RuntimeException('Refresh token not found.');
        }

        // Reuse detection: if this token was already revoked by a rotation
        if ($old->isRevoked() && $old->getReplacedBy() !== null) {
            // Revoke ALL user tokens — potential token theft (must persist regardless of transaction)
            $this->refreshRepo->revokeAllForUser($old->getUser());
            throw new \RuntimeException('Token reuse detected. All tokens revoked.');
        }

        if ($old->isRevoked() || $old->isExpired()) {
            throw new \RuntimeException('Refresh token is invalid or expired.');
        }

        $user = $old->getUser();

        $this->em->beginTransaction();
        try {
            // Revoke old token
            $old->revoke();

            // Create new refresh token
            $newPlain = bin2hex(random_bytes(48));
            $newHash = $this->hashRefreshToken($newPlain);
            $newJti = bin2hex(random_bytes(16));
            $newExpiresAt = (new \DateTimeImmutable())->setTimestamp(time() + $this->refreshTtl);

            $newToken = new RefreshToken($user, $newHash, $newExpiresAt, $newJti);
            $this->em->persist($newToken);
            $this->em->flush();

            // Link old to new (must happen after new is persisted to get its ID)
            $old->setReplacedBy($newToken->getId());
            $this->em->flush();

            $this->em->commit();

            return [
                'access_token' => $this->createAccessToken($user),
                'refresh_token' => $newPlain,
            ];
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $e;
        }
    }

    /**
     * Revoke a specific refresh token by its plaintext value.
     */
    public function revokeRefreshToken(string $plainToken): void
    {
        $hash = $this->hashRefreshToken($plainToken);
        $token = $this->em->getRepository(RefreshToken::class)->findOneBy([
            'refreshTokenHash' => $hash,
        ]);

        if ($token !== null && !$token->isRevoked()) {
            $token->revoke();
            $this->em->flush();
        }
    }

    /**
     * Revoke all refresh tokens for a user.
     */
    public function revokeAllForUser(User $user): void
    {
        $this->refreshRepo->revokeAllForUser($user);
    }

    /**
     * Revoke an access token by adding its JTI to the blacklist.
     * The token is cached until its natural expiration time.
     */
    public function revokeAccessToken(string $token): void
    {
        $payload = $this->decodeAccessTokenWithoutBlacklist($token);
        if ($payload === null) {
            // Token is invalid, cannot revoke
            return;
        }

        $jti = $payload['jti'] ?? null;
        if ($jti === null) {
            return;
        }

        $expiresAt = $payload['exp'];
        $ttl = max(0, $expiresAt - time());

        // Add to revocation blacklist with TTL until token expiration
        // Use hashed key for cache compatibility
        $cacheKey = 'revoked_token_' . hash('xxh64', $jti);
        $item = $this->cache->getItem($cacheKey);
        $item->set(true);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }

    public function getAccessTtl(): int
    {
        return $this->accessTtl;
    }

    private function hashRefreshToken(string $plainToken): string
    {
        return hash_hmac('sha256', $plainToken, $this->refreshSecret);
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string|bool
    {
        $remainder = \strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
