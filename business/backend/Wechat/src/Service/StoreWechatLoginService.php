<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Service;

use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\StoreWechatSetting;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Entity\WechatApplicationRevision;
use NsUltimate\Business\Wechat\Entity\WechatIdentity;

/**
 * Store-scoped customer login/phone/OAuth orchestration.
 *
 * Every flow resolves the published Store configuration first and never accepts a
 * caller-supplied AppID/OpenID as verified identity.
 */
final class StoreWechatLoginService
{
    public function __construct(
        private readonly StoreWechatResolver $resolver,
        private readonly WechatLoginGatewayInterface $gateway,
        private readonly WechatIdentityService $identities,
        private readonly WechatOAuthStateService $oauthStates,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function loginWithMiniApp(string $storeUuid, string $jsCode): User
    {
        $config = $this->resolver->resolve($storeUuid, StoreWechatSetting::CAP_MINIAPP_LOGIN);
        $session = $this->gateway->miniappSession($config, $jsCode);

        return $this->identities->findOrCreate(
            appId: (string) $config->appId,
            applicationType: WechatApplication::TYPE_MINIAPP,
            openid: $session['openid'],
            unionid: $session['unionid'] ?? null,
        );
    }

    public function bindPhone(User $user, string $storeUuid, string $code): void
    {
        $config = $this->resolver->resolve($storeUuid, StoreWechatSetting::CAP_MINIAPP_LOGIN);
        if (!$this->identities->hasIdentityForUser($user, (string) $config->appId, WechatApplication::TYPE_MINIAPP)) {
            throw new \InvalidArgumentException('The authenticated user has no identity for this application.');
        }

        $user->setPhone($this->gateway->phoneNumber($config, $code));
        $user->setPhoneVerified(true);
        $this->entityManager->flush();
    }

    public function oauthUrl(string $storeUuid, string $redirectUri): string
    {
        $config = $this->resolver->resolve($storeUuid, StoreWechatSetting::CAP_OFFICIAL_OAUTH);
        $host = $this->assertAllowedRedirect($redirectUri, $config->redirectHosts);

        $revision = $this->entityManager->getRepository(WechatApplicationRevision::class)
            ->findOneBy(['uuid' => $config->applicationRevisionUuid]);
        if (!$revision instanceof WechatApplicationRevision) {
            throw new \RuntimeException('The resolved application revision is missing.');
        }

        $state = $this->oauthStates->issue($storeUuid, $revision, $host);

        return $this->gateway->oauthRedirectUrl($config, $this->appendState($redirectUri, $state));
    }

    public function oauthCallback(string $storeUuid, string $code, string $state): User
    {
        $record = $this->oauthStates->consume($storeUuid, $state);

        $config = $this->resolver->resolve($storeUuid, StoreWechatSetting::CAP_OFFICIAL_OAUTH);
        if ($record->getApplicationRevision()?->getUuid() !== $config->applicationRevisionUuid) {
            throw new \InvalidArgumentException('The application changed since authorization started; restart the authorization.');
        }

        $data = $this->gateway->oauthUser($config, $code);

        return $this->identities->findOrCreate(
            appId: (string) $config->appId,
            applicationType: WechatApplication::TYPE_OFFICIAL,
            openid: $data['openid'],
            unionid: $data['unionid'] ?? null,
        );
    }

    public function linkIdentity(User $user, string $storeUuid, string $jsCode): WechatIdentity
    {
        $config = $this->resolver->resolve($storeUuid, StoreWechatSetting::CAP_MINIAPP_LOGIN);
        $session = $this->gateway->miniappSession($config, $jsCode);

        return $this->identities->link(
            $user,
            (string) $config->appId,
            WechatApplication::TYPE_MINIAPP,
            $session['openid'],
            unionid: $session['unionid'] ?? null,
        );
    }

    /** @param list<string> $allowedHosts */
    private function assertAllowedRedirect(string $redirectUri, array $allowedHosts): string
    {
        $parts = parse_url($redirectUri);
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
            throw new \InvalidArgumentException('redirect_uri must be an absolute HTTP(S) URL.');
        }
        if ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new \InvalidArgumentException('redirect_uri must use HTTPS.');
        }
        if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
            throw new \InvalidArgumentException('redirect_uri host is not allowlisted for this store.');
        }

        return $host;
    }

    private function appendState(string $redirectUri, string $state): string
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri.$separator.http_build_query(['state' => $state]);
    }
}
