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

/**
 * Covers the remaining branches of src/Identity/Controller/AuthController.php
 * (uncovered lines 280, 294, 295, 296, 297, 300, 387 in var/uncovered-map.txt)
 * that the existing AuthControllerTest does not reach:
 *   - 280: verifyOtp(login) when the phone user is unknown or not verified
 *   - 294-300: verifyOtp(verify_phone) branch (no AuthController test exercised it)
 *   - 387: logout with a valid-but-non-array JSON body
 * plus login-with-verified-phone and skipped bug-repro tests.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuthControllerCoverageTest extends TestCase
{
    private TokenManager $tokenManager;
    private UserRepository $userRepository;
    private UserPasswordHasherInterface $hasher;
    private OtpServiceInterface $otpService;
    private UserServiceInterface $userService;
    private EntityManagerInterface $em;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->otpService = $this->createMock(OtpServiceInterface::class);
        $this->userService = $this->createMock(UserServiceInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $msg) => $msg);

        $this->controller = new AuthController(
            $this->tokenManager,
            $this->userRepository,
            $this->hasher,
            $this->otpService,
            $this->userService,
            $this->em,
            'TPL_LOGIN',
            'TPL_VERIFY',
            $translator,
        );
    }

    public function testVerifyOtpLoginRejectsUnverifiedPhone(): void
    {
        $user = (new User())
            ->setEmail('u@example.com')
            ->setUsername('u')
            ->setPhone('+8613912345678')
            ->setPhoneVerified(false)
            ->setPassword('hash');

        $this->otpService->method('verify')->willReturn(true);
        $this->userRepository->method('findByPhone')->with('+8613912345678')->willReturn($user);

        $request = Request::create(
            '/api/auth/otp/verify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613912345678',
                'otp' => '123456',
                'purpose' => 'login',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->verifyOtp($request);
        self::assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Phone not verified or user not found.', (string) ($body['message'] ?? ''));
    }

    public function testVerifyOtpLoginRejectsUnknownUser(): void
    {
        $this->otpService->method('verify')->willReturn(true);
        $this->userRepository->method('findByPhone')->with('+8613999999999')->willReturn(null);

        $request = Request::create(
            '/api/auth/otp/verify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613999999999',
                'otp' => '123456',
                'purpose' => 'login',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->verifyOtp($request);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testVerifyOtpVerifyPhoneMarksPhoneVerified(): void
    {
        $user = (new User())
            ->setEmail('vp@example.com')
            ->setUsername('vp')
            ->setPhone('+8613912345678')
            ->setPhoneVerified(false)
            ->setPassword('hash');

        $this->otpService->method('verify')->willReturn(true);
        $this->userRepository->method('findByPhone')->with('+8613912345678')->willReturn($user);
        $this->em->expects(self::once())->method('flush');

        $request = Request::create(
            '/api/auth/otp/verify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613912345678',
                'otp' => '123456',
                'purpose' => 'verify_phone',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->verifyOtp($request);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['phone_verified'] ?? false);
        self::assertTrue($user->isPhoneVerified());
    }

    public function testVerifyOtpVerifyPhoneReportsSuccessForUnknownUser(): void
    {
        // Characterizes Bug B: a phone with no user account still gets
        // phone_verified=true (no flush, nothing persisted). See report.
        $this->otpService->method('verify')->willReturn(true);
        $this->userRepository->method('findByPhone')->with('+8613912345678')->willReturn(null);
        $this->em->expects(self::never())->method('flush');

        $request = Request::create(
            '/api/auth/otp/verify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613912345678',
                'otp' => '123456',
                'purpose' => 'verify_phone',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->verifyOtp($request);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['phone_verified'] ?? false);
    }

    public function testLogoutTreatsNonArrayJsonAsEmptyBody(): void
    {
        $request = Request::create(
            '/api/auth/logout',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '42',
        );

        $this->tokenManager->expects(self::never())->method('revokeAccessToken');
        $this->tokenManager->expects(self::never())->method('revokeRefreshToken');

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testRefreshSuccessReturnsRotatedTokens(): void
    {
        $this->tokenManager->method('rotateRefreshToken')->with('valid-token')->willReturn([
            'access_token' => 'new_access',
            'refresh_token' => 'new_refresh',
        ]);
        $this->tokenManager->method('getAccessTtl')->willReturn(7200);

        $request = Request::create(
            '/api/auth/token/refresh',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'refresh_token' => 'valid-token',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->refresh($request);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('new_access', $body['access_token']);
        self::assertSame('new_refresh', $body['refresh_token']);
        self::assertSame(7200, $body['expires_in']);
    }

    public function testLoginWithVerifiedPhoneReturnsTokens(): void
    {
        $user = (new User())
            ->setEmail('phoneok@example.com')
            ->setUsername('phoneok')
            ->setPhone('+8613912345678')
            ->setPhoneVerified(true)
            ->setPassword('hash');

        $this->userRepository->method('findByPhone')->with('+8613912345678')->willReturn($user);
        $this->hasher->method('isPasswordValid')->with($user, 'Correct123!')->willReturn(true);
        $this->tokenManager->method('createAccessToken')->willReturn('access_phone');
        $this->tokenManager->method('createRefreshToken')->willReturn('refresh_phone');
        $this->tokenManager->method('getAccessTtl')->willReturn(7200);

        $request = Request::create(
            '/api/auth/login',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'identifier' => '+8613912345678',
                'password' => 'Correct123!',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->login($request);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('access_phone', $body['access_token']);
        self::assertSame('refresh_phone', $body['refresh_token']);
    }

    /**
     * Correct-behavior test for Bug D: a user whose username consists only of
     * digits must still be able to log in with that username. AuthController::login()
     * routes every phone-looking identifier through findByPhone() and never falls
     * back to findByIdentifier(), so the numeric username is unreachable → 401.
     *
     * Skipped because the src code never consults findByIdentifier() here.
     */
    public function testLoginWithNumericUsernameShouldSucceed(): void
    {
        self::markTestSkipped(
            'Bug D: AuthController::login() treats any phone-looking identifier as a phone '
            . 'and never falls back to findByIdentifier(), so users with purely numeric '
            . 'usernames can never log in with their username. '
            . 'See docs/issues/coverage-2026-08-09/identity-controllers.md.'
        );

        $user = (new User())
            ->setEmail('num@example.com')
            ->setUsername('13800138000')
            ->setPassword('hash');

        $this->userRepository->method('findByPhone')->with('13800138000')->willReturn(null);
        $this->userRepository->method('findByIdentifier')->with('13800138000')->willReturn($user);
        $this->hasher->method('isPasswordValid')->with($user, 'Correct123!')->willReturn(true);

        $request = Request::create(
            '/api/auth/login',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'identifier' => '13800138000',
                'password' => 'Correct123!',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->login($request);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Correct-behavior test for Bug A: logout() with a malformed JSON body must
     * not raise an uncaught JsonException (500). Skipped because src/AuthController::logout()
     * only guards the empty-body case.
     */
    public function testLogoutWithMalformedJsonShouldNotThrow(): void
    {
        self::markTestSkipped(
            'Bug A: AuthController::logout() guards the empty-body case but still calls '
            . 'json_decode(..., JSON_THROW_ON_ERROR) for any non-empty body, so malformed JSON '
            . 'raises an uncaught JsonException (HTTP 500). '
            . 'See docs/issues/coverage-2026-08-09/identity-controllers.md.'
        );

        $request = Request::create(
            '/api/auth/logout',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{oops',
        );

        $response = $this->controller->logout($request);
        self::assertSame(204, $response->getStatusCode());
    }
}
