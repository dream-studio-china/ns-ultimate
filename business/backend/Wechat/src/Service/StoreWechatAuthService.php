<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\WechatAuthServiceInterface;
use App\Wechat\Service\WechatServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use Symfony\Component\HttpFoundation\RequestStack;

final class StoreWechatAuthService implements WechatAuthServiceInterface
{
    public function __construct(
        private readonly WechatServiceInterface $wechatService,
        private readonly StoreWechatResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly WechatUserRepository $wechatUsers,
        private readonly string $legacyMiniappAppId,
        private readonly string $legacyOfficialAppId,
    ) {
    }

    public function authenticateFromMiniApp(string $jsCode): User
    {
        $application = $this->resolver->application($this->storeUuid(), WechatApplication::TYPE_MINIAPP)['profile'];
        $data = $this->wechatService->code2Session($jsCode);

        return $this->findOrCreate($application->getAppId(), $data['openid'], WechatApplication::TYPE_MINIAPP, [
            'unionid' => $data['unionid'] ?? null,
        ]);
    }

    public function authenticateFromOfficialAccount(string $code): User
    {
        $application = $this->resolver->application($this->storeUuid(), WechatApplication::TYPE_OFFICIAL)['profile'];
        $data = $this->wechatService->getOAuthUser($code);

        return $this->findOrCreate($application->getAppId(), $data['openid'], WechatApplication::TYPE_OFFICIAL, $data);
    }

    public function bindPhone(User $user, string $code): void
    {
        $application = $this->resolver->application($this->storeUuid(), WechatApplication::TYPE_MINIAPP)['profile'];
        $wechatUser = $this->wechatUsers->findByUserAndAppId($user, $application->getAppId());
        if (!$wechatUser instanceof WechatUser) {
            throw new \RuntimeException('Sign in with this Store Mini Program before binding its phone number.');
        }
        $data = $this->wechatService->getPhoneNumber($code);
        $user->setPhone($data['phoneNumber'])->setPhoneVerified(true);
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $profile */
    private function findOrCreate(string $appId, string $openid, string $type, array $profile = []): User
    {
        $wechatUser = $this->wechatUsers->findByAppIdAndOpenid($appId, $openid);
        if ($wechatUser === null) {
            $legacyAppId = $type === WechatApplication::TYPE_MINIAPP ? $this->legacyMiniappAppId : $this->legacyOfficialAppId;
            if ($legacyAppId !== '' && hash_equals($legacyAppId, $appId)) {
                $wechatUser = $this->wechatUsers->findLegacyByOpenidAndAppType($openid, $type);
                $wechatUser?->setAppId($appId);
            }
        }
        if ($wechatUser instanceof WechatUser) {
            $this->applyProfile($wechatUser, $profile);
            $wechatUser->setLastLoginAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $wechatUser->getUser();
        }

        $identifier = substr(hash('sha256', $appId.'\0'.$openid), 0, 24);
        $user = (new User())
            ->setEmail('wx_'.$identifier.'@wechat.local')
            ->setUsername('wx_'.$identifier)
            ->setPassword(bin2hex(random_bytes(32)));
        $this->entityManager->persist($user);
        $wechatUser = new WechatUser($user, $openid, $type, $appId);
        $this->applyProfile($wechatUser, $profile);
        $this->entityManager->persist($wechatUser);
        $this->entityManager->flush();

        return $user;
    }

    /** @param array<string, mixed> $profile */
    private function applyProfile(WechatUser $wechatUser, array $profile): void
    {
        if (is_string($profile['unionid'] ?? null)) {
            $wechatUser->setUnionid($profile['unionid']);
        }
        if (is_string($profile['nickname'] ?? null)) {
            $wechatUser->setNickname($profile['nickname']);
        }
        if (is_string($profile['avatar'] ?? null)) {
            $wechatUser->setAvatar($profile['avatar']);
        }
        if (is_string($profile['province'] ?? null)) {
            $wechatUser->setProvince($profile['province']);
        }
        if (is_string($profile['city'] ?? null)) {
            $wechatUser->setCity($profile['city']);
        }
        if (is_string($profile['country'] ?? null)) {
            $wechatUser->setCountry($profile['country']);
        }
        if (is_int($profile['sex'] ?? null) && $profile['sex'] > 0) {
            $wechatUser->setSex($profile['sex']);
        }
    }

    private function storeUuid(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $body = json_decode($request?->getContent() ?: '{}', true);
        $uuid = $request?->attributes->get('scopeId')
            ?? $request?->query->get('store_uuid')
            ?? (is_array($body) ? ($body['store_uuid'] ?? null) : null);
        if (!is_string($uuid) || $uuid === '') {
            throw new \RuntimeException('store_uuid is required for WeChat login.');
        }

        return $uuid;
    }
}
