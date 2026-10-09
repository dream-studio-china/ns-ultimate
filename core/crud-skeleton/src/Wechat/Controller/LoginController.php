<?php

declare(strict_types=1);

namespace App\Wechat\Controller;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Wechat\Service\WechatAuthServiceInterface;
use App\Wechat\Service\WechatServiceInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/wechat', name: 'wechat-')]
class LoginController extends AbstractController
{
    public function __construct(
        private readonly WechatAuthServiceInterface $wechatAuthService,
        private readonly TokenManager $tokenManager,
        private readonly WechatServiceInterface $wechatService,
        private readonly TranslatorInterface $translator,
    ) {}

    #[OA\Post(
        path: '/api/wechat/miniapp/login',
        summary: 'Mini Program login — js_code to JWT',
        description: 'Exchanges WeChat Mini Program js_code for openid/unionid, creates or finds user, returns JWT tokens.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['js_code'],
                properties: [
                    new OA\Property(property: 'js_code', type: 'string', description: 'WeChat wx.login() returned code', example: '081abc...'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login success, tokens returned'),
            new OA\Response(response: 400, description: 'js_code missing'),
            new OA\Response(response: 401, description: 'WeChat API error (invalid code)'),
        ],
        tags: ['Wechat']
    )]
    #[Route('/miniapp/login', name: 'miniapp-login', methods: ['POST'])]
    public function miniappLogin(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $jsCode = trim((string) ($data['js_code'] ?? ''));

        if ($jsCode === '') {
            return $this->error('js_code is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = $this->wechatAuthService->authenticateFromMiniApp($jsCode);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        return $this->tokenResponse($user);
    }

    #[OA\Post(
        path: '/api/wechat/miniapp/phone',
        summary: 'Mini Program — bind phone number',
        description: 'Exchanges WeChat phone number code for the user\'s phone, verifies and stores it. Requires authentication.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', description: 'WeChat getPhoneNumber code from frontend', example: 'xyz...'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Phone bound successfully'),
            new OA\Response(response: 400, description: 'code missing or WeChat API error'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ],
        tags: ['Wechat']
    )]
    #[Route('/miniapp/phone', name: 'miniapp-phone', methods: ['POST'])]
    public function miniappPhone(Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if ($user === null) {
            return $this->error('Authentication required.', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            return $this->error('code is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->wechatAuthService->bindPhone($user, $code);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[OA\Get(
        path: '/api/wechat/oauth/url',
        summary: 'Official Account — OAuth redirect URL',
        description: 'Returns the WeChat Official Account OAuth authorization URL for snsapi_userinfo scope.',
        parameters: [
            new OA\Parameter(name: 'redirect_uri', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'Callback URL after authorization', example: 'https://example.com/wechat/callback'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OAuth URL returned'),
            new OA\Response(response: 400, description: 'redirect_uri missing'),
        ],
        tags: ['Wechat']
    )]
    #[Route('/oauth/url', name: 'oauth-url', methods: ['GET'])]
    public function oauthUrl(Request $request): JsonResponse
    {
        $redirectUri = trim((string) $request->query->get('redirect_uri', ''));

        if ($redirectUri === '') {
            return $this->error('redirect_uri is required.', Response::HTTP_BAD_REQUEST);
        }

        $url = $this->wechatService->getOAuthRedirectUrl($redirectUri);

        return new JsonResponse(['url' => $url]);
    }

    #[OA\Post(
        path: '/api/wechat/oauth/callback',
        summary: 'Official Account — OAuth callback',
        description: 'Exchanges WeChat OAuth code for user info (openid, nickname, avatar), creates or finds user, returns JWT tokens.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', description: 'WeChat OAuth authorization code', example: '081abc...'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login success, tokens returned'),
            new OA\Response(response: 400, description: 'code missing'),
            new OA\Response(response: 401, description: 'WeChat OAuth error'),
        ],
        tags: ['Wechat']
    )]
    #[Route('/oauth/callback', name: 'oauth-callback', methods: ['POST'])]
    public function oauthCallback(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $code = trim((string) ($data['code'] ?? ''));

        if ($code === '') {
            return $this->error('code is required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = $this->wechatAuthService->authenticateFromOfficialAccount($code);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        return $this->tokenResponse($user);
    }

    private function tokenResponse(User $user): JsonResponse
    {
        return new JsonResponse([
            'access_token' => $this->tokenManager->createAccessToken($user),
            'expires_in' => $this->tokenManager->getAccessTtl(),
            'refresh_token' => $this->tokenManager->createRefreshToken($user),
        ]);
    }

    private function error(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse([
            'code' => $status,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
