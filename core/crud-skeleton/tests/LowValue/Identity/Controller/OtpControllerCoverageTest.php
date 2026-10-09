<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Identity\Controller;


use PHPUnit\Framework\Attributes\Group;
use App\Identity\Controller\OtpController;
use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Security\TokenManager;
use App\Identity\Service\OtpServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the remaining branches of src/Identity/Controller/OtpController.php
 * (uncovered lines 42, 53, 65, 69, 73, 79 in var/uncovered-map.txt) that the
 * existing OtpControllerTest does not reach:
 *   - 42: requestOtp with an invalid purpose
 *   - 53: requestOtp success (204)
 *   - 65: verifyOtp with missing phone/otp
 *   - 69: verifyOtp with an invalid purpose
 *   - 73: verifyOtp with an invalid/expired OTP
 *   - 79: verifyOtp(login) when the phone user is unknown or not verified
 * plus the verify_phone unknown-user branch and skipped bug-repro tests.
 */
#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class OtpControllerCoverageTest extends TestCase
{
    private TokenManager $tokenManager;
    private UserRepository $userRepository;
    private OtpServiceInterface $otpService;
    private EntityManagerInterface $em;
    private OtpController $controller;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->otpService = $this->createMock(OtpServiceInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $msg) => $msg);

        $this->controller = new OtpController(
            $this->tokenManager,
            $this->userRepository,
            $this->otpService,
            $this->em,
            'TPL_LOGIN',
            'TPL_VERIFY',
            $translator,
        );
    }

    public function testRequestOtpRejectsInvalidPurpose(): void
    {
        $request = Request::create(
            '/api/auth/otp/request',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613912345678',
                'purpose' => 'invalid',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->requestOtp($request);
        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Invalid purpose', (string) ($body['message'] ?? ''));
    }

    public function testRequestOtpSuccessReturnsNoContent(): void
    {
        $this->otpService->expects(self::once())
            ->method('generateAndSend')
            ->with('+8613912345678', 'verify_phone', 'TPL_VERIFY');

        $request = Request::create(
            '/api/auth/otp/request',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613912345678',
                'purpose' => 'verify_phone',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->requestOtp($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testVerifyOtpRequiresPhoneAndOtp(): void
    {
        $request = Request::create(
            '/api/auth/otp/verify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'purpose' => 'login',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->verifyOtp($request);
        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Phone and OTP are required.', (string) ($body['message'] ?? ''));
    }

    public function testVerifyOtpRejectsInvalidPurpose(): void
    {
        $request = Request::create(
            '/api/auth/otp/verify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613912345678',
                'otp' => '123456',
                'purpose' => 'nope',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->verifyOtp($request);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testVerifyOtpRejectsInvalidOtp(): void
    {
        $this->otpService->expects(self::once())->method('verify')->willReturn(false);

        $request = Request::create(
            '/api/auth/otp/verify',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'phone' => '+8613912345678',
                'otp' => '000000',
                'purpose' => 'login',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->verifyOtp($request);
        self::assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid or expired OTP.', (string) ($body['message'] ?? ''));
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

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Phone not verified or user not found.', (string) ($body['message'] ?? ''));
    }

    public function testVerifyOtpLoginRejectsUnverifiedPhone(): void
    {
        $user = (new User())
            ->setEmail('unverified@example.com')
            ->setUsername('unverified')
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
    }

    public function testVerifyOtpVerifyPhoneReportsSuccessForUnknownUser(): void
    {
        // Characterizes Bug B: a phone with no user account still gets
        // phone_verified=true (no flush). See report.
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

    /**
     * Correct-behavior test for Bug A: a malformed/empty JSON body must be
     * answered with 400, not bubble up an uncaught JsonException (500).
     *
     * Skipped because src/OtpController::requestOtp() calls
     * json_decode(..., JSON_THROW_ON_ERROR) without a guard.
     */
    public function testRequestOtpWithMalformedBodyShouldReturnBadRequest(): void
    {
        self::markTestSkipped(
            'Bug A: OtpController::requestOtp() decodes the body with JSON_THROW_ON_ERROR and '
            . 'without a guard; an empty/invalid body raises an uncaught JsonException (HTTP 500). '
            . 'See docs/issues/coverage-2026-08-09/identity-controllers.md.'
        );

        $request = Request::create(
            '/api/auth/otp/request',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{not json',
        );

        $response = $this->controller->requestOtp($request);
        self::assertSame(400, $response->getStatusCode());
    }
}
