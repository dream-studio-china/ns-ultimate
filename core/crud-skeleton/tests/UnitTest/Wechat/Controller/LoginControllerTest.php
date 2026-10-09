<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Controller;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Wechat\Controller\LoginController;
use App\Wechat\Service\WechatAuthServiceInterface;
use App\Wechat\Service\WechatServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class LoginControllerTest extends TestCase
{
    private WechatAuthServiceInterface $authService;
    private TokenManager $tokenManager;
    private WechatServiceInterface $wechatService;
    private LoginController $controller;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(WechatAuthServiceInterface::class);
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->wechatService = $this->createMock(WechatServiceInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn(string $msg) => $msg);

        $this->controller = new LoginController(
            $this->authService,
            $this->tokenManager,
            $this->wechatService,
            $translator,
        );
    }

    public function testMiniappLoginSuccess(): void
    {
        $user = new User();

        $this->authService->method('authenticateFromMiniApp')
            ->with('valid_js_code')
            ->willReturn($user);

        $this->tokenManager->method('createAccessToken')->willReturn('access_token_123');
        $this->tokenManager->method('createRefreshToken')->willReturn('refresh_token_123');
        $this->tokenManager->method('getAccessTtl')->willReturn(7200);

        $request = Request::create(
            '/api/wechat/miniapp/login',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['js_code' => 'valid_js_code'])
        );

        $response = $this->controller->miniappLogin($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('access_token_123', $body['access_token']);
        self::assertSame(7200, $body['expires_in']);
        self::assertSame('refresh_token_123', $body['refresh_token']);
    }

    public function testMiniappLoginMissingJsCode(): void
    {
        $request = Request::create(
            '/api/wechat/miniapp/login',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['js_code' => ''])
        );

        $response = $this->controller->miniappLogin($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('js_code', $body['message']);
    }

    #[Group('low-value')]
    public function testMiniappLoginEmptyBody(): void
    {
        $request = Request::create(
            '/api/wechat/miniapp/login',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([])
        );

        $response = $this->controller->miniappLogin($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testMiniappLoginWechatApiError(): void
    {
        $this->authService->method('authenticateFromMiniApp')
            ->with('bad_code')
            ->willThrowException(new \RuntimeException('Invalid code'));

        $request = Request::create(
            '/api/wechat/miniapp/login',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['js_code' => 'bad_code'])
        );

        $response = $this->controller->miniappLogin($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Invalid code', $body['message']);
    }

    public function testOauthUrlSuccess(): void
    {
        $this->wechatService->method('getOAuthRedirectUrl')
            ->with('https://example.com/callback')
            ->willReturn('https://open.weixin.qq.com/connect/oauth2/authorize?...');

        $request = Request::create(
            '/api/wechat/oauth/url?redirect_uri=https://example.com/callback',
            'GET'
        );

        $response = $this->controller->oauthUrl($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringStartsWith('https://open.weixin.qq.com/', $body['url']);
    }

    public function testOauthUrlMissingRedirectUri(): void
    {
        $request = Request::create('/api/wechat/oauth/url', 'GET');

        $response = $this->controller->oauthUrl($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testOauthCallbackSuccess(): void
    {
        $user = new User();

        $this->authService->method('authenticateFromOfficialAccount')
            ->with('valid_oauth_code')
            ->willReturn($user);

        $this->tokenManager->method('createAccessToken')->willReturn('at');
        $this->tokenManager->method('createRefreshToken')->willReturn('rt');
        $this->tokenManager->method('getAccessTtl')->willReturn(7200);

        $request = Request::create(
            '/api/wechat/oauth/callback',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => 'valid_oauth_code'])
        );

        $response = $this->controller->oauthCallback($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('at', $body['access_token']);
    }

    public function testOauthCallbackMissingCode(): void
    {
        $request = Request::create(
            '/api/wechat/oauth/callback',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([])
        );

        $response = $this->controller->oauthCallback($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testMiniappPhoneMissingCode(): void
    {
        $user = new User();

        $request = Request::create(
            '/api/wechat/miniapp/phone',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([])
        );

        $response = $this->controller->miniappPhone($request, $user);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testMiniappPhoneNoUser(): void
    {
        $request = Request::create(
            '/api/wechat/miniapp/phone',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => 'test'])
        );

        $response = $this->controller->miniappPhone($request, null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
