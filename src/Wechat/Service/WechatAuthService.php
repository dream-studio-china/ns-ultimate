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
        $appId = $this->wechatService->getMiniApp()->getAccount()->getAppId();

        return $this->findOrCreateUser(
            openid: $data['openid'],
            appId: $appId,
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
        $appId = $this->wechatService->getOfficialAccount()->getAccount()->getAppId();

        return $this->findOrCreateUser(
            openid: $data['openid'],
            appId: $appId,
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
        string $appId,
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
        $wechatUser = $this->wechatUserRepository->findByAppIdAndOpenid($appId, $openid);
        if ($wechatUser === null) {
            // Existing Core WechatUser rows predate AppID-aware identities.
            $wechatUser = $this->wechatUserRepository->findLegacyByOpenidAndAppType($openid, $appType);
            $wechatUser?->setAppId($appId);
        }

        if ($wechatUser !== null) {
            if ($unionid !== null) {
                $wechatUser->setUnionid($unionid);
            }
            $wechatUser->setSessionKey($sessionKey);
            if ($nickname !== '') {
                $wechatUser->setNickname($nickname);
            }
            if ($avatar !== '') {
                $wechatUser->setAvatar($avatar);
            }
            if ($sex > 0) {
                $wechatUser->setSex($sex);
            }
            if ($province !== '') {
                $wechatUser->setProvince($province);
            }
            if ($city !== '') {
                $wechatUser->setCity($city);
            }
            if ($country !== '') {
                $wechatUser->setCountry($country);
            }
            $wechatUser->setLastLoginAt(new \DateTimeImmutable());
            if ($rawData !== null) {
                $wechatUser->setRawData($rawData);
            }
            $this->em->flush();

            return $wechatUser->getUser();
        }

        $identifier = substr(hash('sha256', $appId."\0".$openid), 0, 24);
        $user = new User();
        $user->setEmail(sprintf('wx_%s@wechat.local', $identifier));
        $user->setUsername(sprintf('wx_%s', $identifier));
        $user->setPassword(bin2hex(random_bytes(32)));
        $this->em->persist($user);

        $wechatUser = new WechatUser($user, $openid, $appType, $appId);
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
