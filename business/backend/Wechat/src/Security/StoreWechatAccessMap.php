<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Composition adapter that exposes the Store-scoped WeChat customer login and
 * OAuth endpoints as anonymous, without modifying the core security subtree.
 *
 * Only the login/URL/callback endpoints are public; phone binding and identity
 * linking still require an authenticated user.
 */
final class StoreWechatAccessMap implements AccessMapInterface
{
    /** @var list<string> */
    private const PUBLIC_PATTERNS = [
        '#^/(api/v1/)?app/stores/[0-9a-fA-F-]{36}/wechat/miniapp/login$#',
        '#^/(api/v1/)?app/stores/[0-9a-fA-F-]{36}/wechat/oauth/url$#',
        '#^/(api/v1/)?app/stores/[0-9a-fA-F-]{36}/wechat/oauth/callback$#',
        '#^/(api/v1/)?webhooks/wechat/notify/[0-9a-fA-F-]{36}$#',
    ];

    public function __construct(private readonly AccessMapInterface $inner)
    {
    }

    public function getPatterns(Request $request): array
    {
        $path = $request->getPathInfo();
        foreach (self::PUBLIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return [['PUBLIC_ACCESS'], null];
            }
        }

        return $this->inner->getPatterns($request);
    }
}
