<?php

declare(strict_types=1);

namespace App\Wechat\Service;

use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Repository\WechatUserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class WechatAuthService implements WechatAuthServiceInterface
{
    public function __construct(
        private readonly WechatServiceInterface $wechatService,
        private readonly WechatUserRepository $wechatUserRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Mini Program login: js_code → User
     */
    public function authenticateFromMiniApp(string $jsCode): User
    {
        $data = $this->wechatService->code2Session($jsCode);

        return $this->findOrCreateUser(
            openid: $data['openid'],
            unionid: $data['unionid'] ?? null,
            appType: WechatUser::APP_TYPE_MINIAPP,
            sessionKey: $data['session_key'],
        );
    }

    /**
     * Official Account login: oauth code → User
     */
    public function authenticateFromOfficialAccount(string $code): User
    {
        $data = $this->wechatService->getOAuthUser($code);

        $wechatUser = $this->wechatUserRepository->findByOpenid($data['openid']);

        if ($wechatUser !== null) {
            $wechatUser->setNickname($data['nickname']);
            $wechatUser->setAvatar($data['avatar']);
            $wechatUser->setSex($data['sex']);
            $wechatUser->setProvince($data['province']);
            $wechatUser->setCity($data['city']);
            $wechatUser->setCountry($data['country']);
            $wechatUser->setLastLoginAt(new \DateTimeImmutable());
            $wechatUser->setRawData($data);
            $this->em->flush();

            return $wechatUser->getUser();
        }

        return $this->findOrCreateUser(
            openid: $data['openid'],
            unionid: $data['unionid'] ?? null,
            appType: WechatUser::APP_TYPE_OFFICIAL,
            nickname: $data['nickname'],
            avatar: $data['avatar'],
            sex: $data['sex'],
            province: $data['province'],
            city: $data['city'],
            country: $data['country'],
            rawData: $data,
        );
    }

    /**
     * Bind phone number to authenticated user
     */
    public function bindPhone(User $user, string $code): void
    {
        $data = $this->wechatService->getPhoneNumber($code);

        $user->setPhone($data['phoneNumber']);
        $user->setPhoneVerified(true);
        $this->em->flush();
    }

    /**
     * Find existing WechatUser by openid, or create new User + WechatUser
     *
     * @param array<string, mixed>|null $rawData
     */
    private function findOrCreateUser(
        string $openid,
        ?string $unionid = null,
        string $appType = WechatUser::APP_TYPE_MINIAPP,
        string $sessionKey = '',
        string $nickname = '',
        string $avatar = '',
        int $sex = 0,
        string $province = '',
        string $city = '',
        string $country = '',
        ?array $rawData = null,
    ): User {
        $wechatUser = $this->wechatUserRepository->findByOpenid($openid);

        if ($wechatUser !== null) {
            if ($unionid !== null) {
                $wechatUser->setUnionid($unionid);
            }
            $wechatUser->setSessionKey($sessionKey);
            $wechatUser->setLastLoginAt(new \DateTimeImmutable());
            $wechatUser->setRawData($rawData);
            $this->em->flush();

            return $wechatUser->getUser();
        }

        $openidSuffix = mb_substr($openid, -8);
        $user = new User();
        $user->setEmail(sprintf('wx_%s@wechat.local', $openidSuffix));
        $user->setUsername(sprintf('wx_%s', $openidSuffix));
        $user->setPassword(bin2hex(random_bytes(32)));
        $this->em->persist($user);

        $wechatUser = new WechatUser($user, $openid, $appType);
        $wechatUser->setUnionid($unionid);
        $wechatUser->setSessionKey($sessionKey);
        $wechatUser->setNickname($nickname);
        $wechatUser->setAvatar($avatar);
        $wechatUser->setSex($sex > 0 ? $sex : null);
        $wechatUser->setProvince($province !== '' ? $province : null);
        $wechatUser->setCity($city !== '' ? $city : null);
        $wechatUser->setCountry($country !== '' ? $country : null);
        $wechatUser->setRawData($rawData);
        $this->em->persist($wechatUser);
        $this->em->flush();

        return $user;
    }
}
