<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Controller;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Wechat\Controller\LoginController;
use App\Wechat\Service\WechatAuthServiceInterface;
use App\Wechat\Service\WechatServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the remaining branches of LoginController that the existing
 * LoginControllerTest does not exercise: the miniapp/phone success and
 * bindPhone error paths, and the OAuth callback RuntimeException path.
 */
#[AllowMockObjectsWithoutExpectations]
final class LoginControllerCoverageTest extends TestCase
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

    private function jsonRequest(string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );
    }

    public function testMiniappPhoneSuccessReturnsNoContent(): void
    {
        $user = new User();

        $response = $this->controller->miniappPhone(
            $this->jsonRequest('/api/wechat/miniapp/phone', ['code' => 'valid_phone_code']),
            $user,
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame([], json_decode($response->getContent(), true));
    }

    public function testMiniappPhoneBindErrorReturnsBadRequest(): void
    {
        $user = new User();
        $this->authService->method('bindPhone')
            ->willThrowException(new \RuntimeException('WeChat phone verification failed'));

        $response = $this->controller->miniappPhone(
            $this->jsonRequest('/api/wechat/miniapp/phone', ['code' => 'bad_phone_code']),
            $user,
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('WeChat phone verification failed', $body['message']);
    }

    public function testOauthCallbackAuthErrorReturnsUnauthorized(): void
    {
        $this->authService->method('authenticateFromOfficialAccount')
            ->with('invalid_oauth_code')
            ->willThrowException(new \RuntimeException('WeChat OAuth failed'));

        $response = $this->controller->oauthCallback(
            $this->jsonRequest('/api/wechat/oauth/callback', ['code' => 'invalid_oauth_code']),
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('WeChat OAuth failed', $body['message']);
    }
}
