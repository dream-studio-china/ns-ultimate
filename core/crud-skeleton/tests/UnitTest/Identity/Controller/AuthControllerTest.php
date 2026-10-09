<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Controller;

use App\Identity\Controller\AuthController;
use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Security\TokenManager;
use App\Identity\Service\OtpServiceInterface;
use App\Identity\Service\UserServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class AuthControllerTest extends TestCase
{
    private TokenManager $tokenManager;
    private UserRepository $userRepository;
    private UserPasswordHasherInterface $hasher;
    private OtpServiceInterface $otpService;
    private EntityManagerInterface $em;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->otpService = $this->createMock(OtpServiceInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $userService = $this->createMock(UserServiceInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn(string $msg) => $msg);

        $this->controller = new AuthController(
            $this->tokenManager,
            $this->userRepository,
            $this->hasher,
            $this->otpService,
            $userService,
            $this->em,
            'TPL_LOGIN',
            'TPL_VERIFY',
            $translator,
        );
    }

    public function testLogoutRevokesBothAccessAndRefreshTokensWhenProvided(): void
    {
        $request = Request::create('/api/auth/logout', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
        ], JSON_THROW_ON_ERROR));

        $this->tokenManager->expects(self::once())->method('revokeAccessToken')->with('access-1');
        $this->tokenManager->expects(self::once())->method('revokeRefreshToken')->with('refresh-1');

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testLogoutUsesAuthorizationHeaderAsAccessTokenFallback(): void
    {
        $request = Request::create('/api/auth/logout', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer bearer-token'], content: '');

        $this->tokenManager->expects(self::once())->method('revokeAccessToken')->with('bearer-token');
        $this->tokenManager->expects(self::never())->method('revokeRefreshToken');

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testLogoutWithEmptyBodyAndNoAuthorizationDoesNotRevoke(): void
    {
        $request = Request::create('/api/auth/logout', 'POST', content: '');

        $this->tokenManager->expects(self::never())->method('revokeAccessToken');
        $this->tokenManager->expects(self::never())->method('revokeRefreshToken');

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testRefreshReturnsBadRequestWhenRefreshTokenMissing(): void
    {
        $request = Request::create('/api/auth/token/refresh', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([], JSON_THROW_ON_ERROR));

        $this->tokenManager->expects(self::never())->method('rotateRefreshToken');

        $response = $this->controller->refresh($request);
        self::assertSame(400, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Refresh token is required', (string) ($payload['message'] ?? ''));
    }

    public function testRefreshReturnsUnauthorizedWhenRotationFails(): void
    {
        $request = Request::create('/api/auth/token/refresh', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'refresh_token' => 'broken-token',
        ], JSON_THROW_ON_ERROR));

        $this->tokenManager->expects(self::once())
            ->method('rotateRefreshToken')
            ->with('broken-token')
            ->willThrowException(new \RuntimeException('Token reuse detected.'));

        $response = $this->controller->refresh($request);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginWithUnverifiedPhoneReturnsForbidden(): void
    {
        $user = (new User())
            ->setEmail('phone@example.com')
            ->setUsername('phone')
            ->setPhone('+8613812345678')
            ->setPhoneVerified(false)
            ->setPassword('hash');

        $this->userRepository->expects(self::once())
            ->method('findByPhone')
            ->with('+8613812345678')
            ->willReturn($user);

        $this->hasher->expects(self::never())->method('isPasswordValid');

        $request = Request::create('/api/auth/login', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'identifier' => '+8613812345678',
            'password' => 'Whatever123!',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->login($request);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testLoginRequiresIdentifierAndPassword(): void
    {
        $request = Request::create('/api/auth/login', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'identifier' => '',
            'password' => '',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->login($request);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Identifier and password are required.', $body['message']);
    }

    public function testLoginWithInvalidCredentialsByEmail(): void
    {
        $request = Request::create('/api/auth/login', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'identifier' => 'nonexistent@example.com',
            'password' => 'wrongpassword',
        ], JSON_THROW_ON_ERROR));

        $this->userRepository->method('findByIdentifier')->with('nonexistent@example.com')->willReturn(null);

        $response = $this->controller->login($request);
        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Invalid credentials.', $body['message']);
    }

    public function testLoginWithWrongPassword(): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setUsername('user')
            ->setPassword('correct_hash');

        $this->userRepository->method('findByIdentifier')->with('user@example.com')->willReturn($user);
        $this->hasher->method('isPasswordValid')->with($user, 'wrongpassword')->willReturn(false);

        $request = Request::create('/api/auth/login', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'identifier' => 'user@example.com',
            'password' => 'wrongpassword',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->login($request);
        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Invalid credentials.', $body['message']);
    }

    public function testRequestOtpRequiresPhone(): void
    {
        $request = Request::create('/api/auth/otp/request', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->requestOtp($request);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Phone number is required.', $body['message']);
    }

    public function testRequestOtpRejectsInvalidPurpose(): void
    {
        $request = Request::create('/api/auth/otp/request', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'purpose' => 'invalid',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->requestOtp($request);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Invalid purpose', $body['message']);
    }

    public function testVerifyOtpRequiresPhoneAndOtp(): void
    {
        $request = Request::create('/api/auth/otp/verify', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->verifyOtp($request);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Phone and OTP are required.', $body['message']);
    }

    public function testVerifyOtpRejectsInvalidPurpose(): void
    {
        $request = Request::create('/api/auth/otp/verify', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'otp' => '123456',
            'purpose' => 'invalid',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->verifyOtp($request);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertStringContainsString('Invalid purpose', $body['message']);
    }

    public function testVerifyOtpInvalidCodeReturnsUnauthorized(): void
    {
        $this->otpService->method('verify')->willReturn(false);

        $request = Request::create('/api/auth/otp/verify', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'otp' => '000000',
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->verifyOtp($request);
        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Invalid or expired OTP.', $body['message']);
    }

    public function testRequestOtpSuccessReturnsNoContent(): void
    {
        $this->otpService->method('generateAndSend')->willReturnCallback(function () {});

        $request = Request::create('/api/auth/otp/request', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->requestOtp($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testRequestOtpRateLimitedReturnsTooManyRequests(): void
    {
        $this->otpService->method('generateAndSend')
            ->willThrowException(new \RuntimeException('OTP sent too recently, please wait.'));

        $request = Request::create('/api/auth/otp/request', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->requestOtp($request);
        self::assertSame(429, $response->getStatusCode());
    }

    public function testVerifyOtpLoginSuccessReturnsTokens(): void
    {
        $user = (new User())
            ->setEmail('otpuser@example.com')
            ->setUsername('otpuser')
            ->setPhone('+8613812345678')
            ->setPhoneVerified(true)
            ->setPassword('hash');

        $this->otpService->method('verify')->willReturn(true);
        $this->userRepository->method('findByPhone')->with('+8613812345678')->willReturn($user);
        $this->tokenManager->method('createAccessToken')->willReturn('access_otp');
        $this->tokenManager->method('createRefreshToken')->willReturn('refresh_otp');
        $this->tokenManager->method('getAccessTtl')->willReturn(7200);

        $request = Request::create('/api/auth/otp/verify', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'otp' => '123456',
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->verifyOtp($request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('access_otp', $body['access_token']);
        self::assertSame('refresh_otp', $body['refresh_token']);
    }

    public function testRegisterSuccessReturnsTokens(): void
    {
        $user = (new User())
            ->setEmail('new@example.com')
            ->setUsername('newuser');

        $userService = $this->createMock(UserServiceInterface::class);
        $userService->method('register')
            ->with('new@example.com', 'newuser', 'P@ssw0rd', null)
            ->willReturn($user);

        $this->tokenManager->method('createAccessToken')->willReturn('access_new');
        $this->tokenManager->method('createRefreshToken')->willReturn('refresh_new');
        $this->tokenManager->method('getAccessTtl')->willReturn(7200);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new AuthController(
            $this->tokenManager, $this->userRepository, $this->hasher,
            $this->otpService, $userService, $this->em,
            'TPL_LOGIN', 'TPL_VERIFY', $translator,
        );

        $request = Request::create('/api/auth/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'new@example.com',
            'username' => 'newuser',
            'password' => 'P@ssw0rd',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->register($request);
        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('access_new', $body['access_token']);
    }

    public function testRegisterWithInvalidArgumentsReturnsBadRequest(): void
    {
        $userService = $this->createMock(UserServiceInterface::class);
        $userService->method('register')
            ->willThrowException(new \InvalidArgumentException('Email, username, and password are required.'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new AuthController(
            $this->tokenManager, $this->userRepository, $this->hasher,
            $this->otpService, $userService, $this->em,
            'TPL_LOGIN', 'TPL_VERIFY', $translator,
        );

        $request = Request::create('/api/auth/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => '',
            'username' => '',
            'password' => '',
        ], JSON_THROW_ON_ERROR));

        $response = $controller->register($request);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testLoginWithValidCredentialsReturnsTokens(): void
    {
        $user = (new User())
            ->setEmail('valid@example.com')
            ->setUsername('validuser')
            ->setPassword('hashed_password');

        $this->userRepository->method('findByIdentifier')->with('valid@example.com')->willReturn($user);
        $this->hasher->method('isPasswordValid')->with($user, 'correct_password')->willReturn(true);
        $this->tokenManager->method('createAccessToken')->willReturn('access_valid');
        $this->tokenManager->method('createRefreshToken')->willReturn('refresh_valid');
        $this->tokenManager->method('getAccessTtl')->willReturn(7200);

        $request = Request::create('/api/auth/login', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'identifier' => 'valid@example.com',
            'password' => 'correct_password',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->login($request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('access_valid', $body['access_token']);
        self::assertSame('refresh_valid', $body['refresh_token']);
    }
}
