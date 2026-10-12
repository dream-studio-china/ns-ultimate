<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\WechatAuthServiceInterface;
use App\Wechat\Service\WechatServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatIdentity;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use Symfony\Component\HttpFoundation\RequestStack;

final class StoreWechatAuthService implements WechatAuthServiceInterface
{
    public function __construct(
        private readonly WechatServiceInterface $wechatService,
        private readonly StoreWechatResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly WechatUserRepository $legacyWechatUsers,
        private readonly string $legacyMiniappAppId,
        private readonly string $legacyOfficialAppId,
    ) {
    }

    public function authenticateFromMiniApp(string $jsCode): User
    {
        $application = $this->resolver->application($this->storeUuid(), WechatApplication::TYPE_MINIAPP)['profile'];
        $data = $this->wechatService->code2Session($jsCode);

        return $this->findOrCreate($application->getAppId(), $data['openid'], WechatApplication::TYPE_MINIAPP);
    }

    public function authenticateFromOfficialAccount(string $code): User
    {
        $application = $this->resolver->application($this->storeUuid(), WechatApplication::TYPE_OFFICIAL)['profile'];
        $data = $this->wechatService->getOAuthUser($code);

        return $this->findOrCreate($application->getAppId(), $data['openid'], WechatApplication::TYPE_OFFICIAL);
    }

    public function bindPhone(User $user, string $code): void
    {
        $application = $this->resolver->application($this->storeUuid(), WechatApplication::TYPE_MINIAPP)['profile'];
        $identity = $this->entityManager->getRepository(StoreWechatIdentity::class)->findOneBy([
            'user' => $user,
            'appId' => $application->getAppId(),
        ]);
        if (!$identity instanceof StoreWechatIdentity) {
            throw new \RuntimeException('Sign in with this Store Mini Program before binding its phone number.');
        }
        $data = $this->wechatService->getPhoneNumber($code);
        $user->setPhone($data['phoneNumber'])->setPhoneVerified(true);
        $this->entityManager->flush();
    }

    private function findOrCreate(string $appId, string $openid, string $type): User
    {
        $repository = $this->entityManager->getRepository(StoreWechatIdentity::class);
        $identity = $repository->findOneBy(['appId' => $appId, 'openid' => $openid]);
        if ($identity instanceof StoreWechatIdentity) {
            return $identity->getUser();
        }

        $legacyAppId = $type === WechatApplication::TYPE_MINIAPP ? $this->legacyMiniappAppId : $this->legacyOfficialAppId;
        if ($legacyAppId !== '' && hash_equals($legacyAppId, $appId)) {
            $legacyIdentity = $this->legacyWechatUsers->findByOpenid($openid);
            if ($legacyIdentity instanceof WechatUser && $legacyIdentity->getAppType() === $type) {
                $this->entityManager->persist(new StoreWechatIdentity($legacyIdentity->getUser(), $appId, $openid, $type));
                $this->entityManager->flush();

                return $legacyIdentity->getUser();
            }
        }

        $identifier = substr(hash('sha256', $appId.'\0'.$openid), 0, 24);
        $user = (new User())
            ->setEmail('wx_'.$identifier.'@wechat.local')
            ->setUsername('wx_'.$identifier)
            ->setPassword(bin2hex(random_bytes(32)));
        $this->entityManager->persist($user);
        $this->entityManager->persist(new StoreWechatIdentity($user, $appId, $openid, $type));
        $this->entityManager->flush();

        return $user;
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
