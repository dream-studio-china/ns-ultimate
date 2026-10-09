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

#[AllowMockObjectsWithoutExpectations]
#[Group('low-value')]
final class OtpControllerTest extends TestCase
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
        $translator->method('trans')->willReturnCallback(fn(string $msg) => $msg);

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

    public function testRequestOtpRequiresPhone(): void
    {
        $request = Request::create('/api/auth/otp/request', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->requestOtp($request);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testRequestOtpReturnsTooManyRequestsWhenServiceThrows(): void
    {
        $this->otpService->expects(self::once())
            ->method('generateAndSend')
            ->willThrowException(new \RuntimeException('Too frequent'));

        $request = Request::create('/api/auth/otp/request', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'purpose' => 'login',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->requestOtp($request);
        self::assertSame(429, $response->getStatusCode());
    }

    public function testVerifyOtpLoginReturnsTokens(): void
    {
        $user = (new User())
            ->setEmail('otp@example.com')
            ->setUsername('otp-user')
            ->setPhone('+8613812345678')
            ->setPhoneVerified(true)
            ->setPassword('hash');

        $this->otpService->expects(self::once())->method('verify')->willReturn(true);
        $this->userRepository->expects(self::once())->method('findByPhone')->willReturn($user);
        $this->tokenManager->expects(self::once())->method('createAccessToken')->willReturn('access-token');
        $this->tokenManager->expects(self::once())->method('createRefreshToken')->willReturn('refresh-token');
        $this->tokenManager->expects(self::once())->method('getAccessTtl')->willReturn(7200);

        $request = Request::create('/api/auth/otp/verify', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'purpose' => 'login',
            'otp' => '123456',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->verifyOtp($request);
        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('access-token', $payload['access_token']);
        self::assertSame('refresh-token', $payload['refresh_token']);
    }

    public function testVerifyOtpVerifyPhoneSetsFlagAndFlushes(): void
    {
        $user = (new User())
            ->setEmail('verify@example.com')
            ->setUsername('verify-user')
            ->setPhone('+8613812345678')
            ->setPhoneVerified(false)
            ->setPassword('hash');

        $this->otpService->expects(self::once())->method('verify')->willReturn(true);
        $this->userRepository->expects(self::once())->method('findByPhone')->willReturn($user);
        $this->em->expects(self::once())->method('flush');

        $request = Request::create('/api/auth/otp/verify', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'phone' => '+8613812345678',
            'purpose' => 'verify_phone',
            'otp' => '123456',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->verifyOtp($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($user->isPhoneVerified());
    }
}
