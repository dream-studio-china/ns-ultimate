<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatIdentity;

/**
 * Resolves and links application-scoped WeChat identities.
 *
 * Identity is always keyed by the verified (AppID, OpenID) pair. A single
 * identity resolves to exactly one system User; a User may own many identities.
 * Equal OpenID strings under different AppIDs never collide, and accounts are
 * never merged implicitly.
 */
final class WechatIdentityService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretCipher $cipher,
    ) {
    }

    /**
     * @param array<string, mixed>|null $rawData
     */
    public function findOrCreate(
        string $appId,
        string $applicationType,
        string $openid,
        ?string $unionid = null,
        string $sessionKey = '',
        ?array $rawData = null,
    ): User {
        if ($appId === '' || $openid === '') {
            throw new \InvalidArgumentException('A WeChat identity requires an AppID and OpenID.');
        }

        $identity = $this->find($appId, $openid);
        if ($identity instanceof WechatIdentity) {
            $this->touch($identity, $unionid, $sessionKey, $rawData);
            $user = $identity->getUser();
            if (!$user instanceof User) {
                throw new \RuntimeException('WeChat identity has no system user.');
            }
            return $user;
        }

        $user = $this->createUser($appId, $openid);
        $identity = (new WechatIdentity())
            ->setUser($user)
            ->setAppId($appId)
            ->setApplicationType($applicationType)
            ->setOpenid($openid);
        $this->touch($identity, $unionid, $sessionKey, $rawData);

        $this->entityManager->persist($user);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Explicitly attach a verified identity to an existing User.
     *
     * Fails when the identity already belongs to another User; no implicit merge.
     */
    public function link(
        User $user,
        string $appId,
        string $applicationType,
        string $openid,
        ?string $unionid = null,
        string $sessionKey = '',
        ?array $rawData = null,
    ): WechatIdentity {
        $identity = $this->find($appId, $openid);

        if ($identity instanceof WechatIdentity) {
            if ($identity->getUser()?->getId() !== $user->getId()) {
                throw new \InvalidArgumentException('This WeChat identity is already linked to another account.');
            }
            $this->touch($identity, $unionid, $sessionKey, $rawData);
            $this->entityManager->flush();

            return $identity;
        }

        $identity = (new WechatIdentity())
            ->setUser($user)
            ->setAppId($appId)
            ->setApplicationType($applicationType)
            ->setOpenid($openid);
        $this->touch($identity, $unionid, $sessionKey, $rawData);

        $this->entityManager->persist($identity);
        $this->entityManager->flush();

        return $identity;
    }

    public function hasIdentityForUser(User $user, string $appId, string $applicationType): bool
    {
        return $this->entityManager->getRepository(WechatIdentity::class)->findOneBy([
            'user' => $user,
            'appId' => $appId,
            'applicationType' => $applicationType,
        ]) instanceof WechatIdentity;
    }

    public function find(string $appId, string $openid): ?WechatIdentity
    {
        $identity = $this->entityManager->getRepository(WechatIdentity::class)
            ->findOneBy(['appId' => $appId, 'openid' => $openid]);

        return $identity instanceof WechatIdentity ? $identity : null;
    }

    public function findForUser(User $user, string $appId, string $applicationType): ?WechatIdentity
    {
        $identity = $this->entityManager->getRepository(WechatIdentity::class)->findOneBy([
            'user' => $user,
            'appId' => $appId,
            'applicationType' => $applicationType,
        ]);

        return $identity instanceof WechatIdentity ? $identity : null;
    }

    private function touch(WechatIdentity $identity, ?string $unionid, string $sessionKey, ?array $rawData): void
    {
        if ($unionid !== null && $unionid !== '') {
            $identity->setUnionid($unionid);
        }
        if ($sessionKey !== '') {
            $identity->setSessionKeyEncrypted($this->cipher->encrypt($sessionKey), $this->cipher->keyId());
        }
        $identity->setRawData($rawData);
        $identity->setLastLoginAt(new \DateTimeImmutable());
    }

    private function createUser(string $appId, string $openid): User
    {
        $discriminator = substr(hash('sha256', $appId.':'.$openid), 0, 16);
        $user = new User();
        $user->setEmail(sprintf('wx_%s@wechat.local', $discriminator));
        $user->setUsername(sprintf('wx_%s', $discriminator));
        $user->setPassword(bin2hex(random_bytes(32)));
        $user->setRoles(['ROLE_USER']);

        return $user;
    }
}
