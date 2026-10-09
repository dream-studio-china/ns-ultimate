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

    public function findByOpenid(string $openid): ?WechatUser
    {
        return $this->findOneBy(['openid' => $openid]);
    }

    public function findByUser(User $user): ?WechatUser
    {
        return $this->findOneBy(['user' => $user]);
    }
}
