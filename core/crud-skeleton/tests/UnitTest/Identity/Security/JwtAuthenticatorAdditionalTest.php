<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Identity\Security;

use App\Identity\Repository\UserRepository;
use App\Identity\Security\JwtAuthenticator;
use App\Identity\Security\TokenManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Locks the remaining JwtAuthenticator branches that are not covered by
 * JwtAuthenticatorTest: onAuthenticationSuccess() and the translated
 * onAuthenticationFailure() message path.
 */
#[AllowMockObjectsWithoutExpectations]
final class JwtAuthenticatorAdditionalTest extends TestCase
{
    private TokenManager $tokenManager;
    private UserRepository $userRepository;
    private JwtAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $msg) => $msg);
        $this->authenticator = new JwtAuthenticator($this->tokenManager, $this->userRepository, $translator);
    }

    #[Group('low-value')]
    public function testOnAuthenticationSuccessReturnsNullToContinue(): void
    {
        $request = new Request();
        $token = $this->createMock(TokenInterface::class);

        self::assertNull($this->authenticator->onAuthenticationSuccess($request, $token, 'main'));
    }

    public function testOnAuthenticationFailureTranslatesMessageKey(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('identity.jwt.expired')
            ->willReturn('Your session has expired.');

        $authenticator = new JwtAuthenticator($this->tokenManager, $this->userRepository, $translator);

        $request = new Request();
        $exception = new CustomUserMessageAuthenticationException('identity.jwt.expired');

        $response = $authenticator->onAuthenticationFailure($request, $exception);

        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Your session has expired.', $payload['message']);
        self::assertSame(401, $payload['code']);
    }

    public function testAuthenticateRejectsTokenWithNonNumericSub(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer token-with-nonnumeric-sub');

        $this->tokenManager->expects(self::once())
            ->method('decodeAccessToken')
            ->with('token-with-nonnumeric-sub')
            ->willReturn(['sub' => 'not-an-int', 'exp' => time() + 60]);

        $this->userRepository->expects(self::once())
            ->method('find')
            ->with(0) // (int) 'not-an-int' === 0
            ->willReturn(null);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('User not found.');

        $passport = $this->authenticator->authenticate($request);
        $passport->getUser();
    }
}
