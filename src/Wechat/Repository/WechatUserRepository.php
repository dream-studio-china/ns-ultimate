<?php

declare(strict_types=1);

namespace App\Wechat\Repository;

use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WechatUser>
 */
class WechatUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WechatUser::class);
    }

    public function findByAppIdAndOpenid(string $appId, string $openid): ?WechatUser
    {
        return $this->findOneBy(['appId' => $appId, 'openid' => $openid]);
    }

    public function findLegacyByOpenidAndAppType(string $openid, string $appType): ?WechatUser
    {
        return $this->findOneBy(['appId' => null, 'openid' => $openid, 'appType' => $appType]);
    }

    public function findByUserAndAppId(User $user, string $appId): ?WechatUser
    {
        return $this->findOneBy(['user' => $user, 'appId' => $appId]);
    }

    /** @deprecated Resolve identities with findByUserAndAppId() when an AppID is known. */
    public function findByUser(User $user): ?WechatUser
    {
        $matches = $this->findBy(['user' => $user]);

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @deprecated Resolve identities with findByAppIdAndOpenid() when an AppID is known. */
    public function findByOpenid(string $openid): ?WechatUser
    {
        $matches = $this->findBy(['openid' => $openid]);

        return count($matches) === 1 ? $matches[0] : null;
    }
}
