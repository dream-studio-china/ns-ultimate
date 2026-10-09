<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Security;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Identity\Security\JwtAuthenticator;
use App\Identity\Security\TokenManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class JwtAuthenticatorTest extends TestCase
{
    private TokenManager $tokenManager;
    private UserRepository $userRepository;
    private JwtAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn(string $msg) => $msg);
        $this->authenticator = new JwtAuthenticator($this->tokenManager, $this->userRepository, $translator);
    }

    public function testSupportsReturnsTrueForBearerAuthorization(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer abc.def.ghi');

        self::assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithoutBearerAuthorization(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Basic xyz');

        self::assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateThrowsForMissingJwtPayload(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Missing JWT token.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsForInvalidJwt(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $this->tokenManager->expects(self::once())
            ->method('decodeAccessToken')
            ->with('invalid-token')
            ->willReturn(null);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired JWT token.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateBuildsPassportAndResolvesUser(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer valid-token');

        $user = new User();
        $user->setEmail('jwt@example.com')->setUsername('jwt-user')->setPassword('hash');

        $this->tokenManager->expects(self::once())
            ->method('decodeAccessToken')
            ->with('valid-token')
            ->willReturn(['sub' => '42', 'exp' => time() + 60]);

        $this->userRepository->expects(self::once())
            ->method('find')
            ->with(42)
            ->willReturn($user);

        $passport = $this->authenticator->authenticate($request);

        self::assertSame($user, $passport->getUser());
    }

    public function testAuthenticateThrowsWhenUserNotFound(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer valid-token');

        $this->tokenManager->expects(self::once())
            ->method('decodeAccessToken')
            ->willReturn(['sub' => '777', 'exp' => time() + 60]);

        $this->userRepository->expects(self::once())
            ->method('find')
            ->with(777)
            ->willReturn(null);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('User not found.');

        $passport = $this->authenticator->authenticate($request);
        $passport->getUser();
    }

    public function testAuthenticationFailureResponseContainsFallbackMessage(): void
    {
        $request = new Request();
        $exception = new class() extends AuthenticationException {
            public function getMessageKey(): string
            {
                return '';
            }
        };

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Authentication failed.', $payload['message']);
    }
}
