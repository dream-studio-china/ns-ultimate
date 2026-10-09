<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\EventListener;

use App\Core\EventListener\ControllerListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ControllerListenerExtendedTest extends TestCase
{
    private function createListener(?TokenStorageInterface $tokenStorage = null, ?object $logger = null): ControllerListener
    {
        if ($tokenStorage === null) {
            $tokenStorage = $this->createStub(TokenStorageInterface::class);
            $tokenStorage->method('getToken')->willReturn(null);
        }

        return new ControllerListener($tokenStorage, $logger ?? new NullLogger());
    }

    private function createControllerEvent(string $method, string $content = ''): ControllerEvent
    {
        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): string => 'ok',
            Request::create('/api/test', $method, [], [], [], [], $content),
            HttpKernelInterface::MAIN_REQUEST
        );
    }

    public function testReturnsEarlyWhenNoToken(): void
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $logger = new class extends NullLogger {
            public int $count = 0;
            public function info(string|\Stringable $message, array $context = []): void { $this->count++; }
        };

        $listener = $this->createListener($tokenStorage, $logger);
        $listener->onKernelController($this->createControllerEvent('POST', 'test'));

        self::assertSame(0, $logger->count);
    }

    public function testLogsForPutMethod(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getId(): int { return 42; }
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'u42'; }
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $logger = new class extends NullLogger {
            public int $count = 0;
            public function info(string|\Stringable $message, array $context = []): void { $this->count++; }
        };

        $listener = $this->createListener($tokenStorage, $logger);
        $listener->onKernelController($this->createControllerEvent('PUT', 'put-body'));

        self::assertSame(1, $logger->count);
    }

    public function testTruncatesContentOver1024Bytes(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getId(): int { return 7; }
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'u7'; }
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $logged = '';
        $logger = new class($logged) extends NullLogger {
            public function __construct(private string &$capture) {}
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->capture = (string) $message;
            }
        };

        $longContent = str_repeat('x', 2000);
        $listener = $this->createListener($tokenStorage, $logger);
        $listener->onKernelController($this->createControllerEvent('POST', $longContent));

        self::assertStringContainsString('...', $logged);
        self::assertStringNotContainsString('xxxxxxxxxxxx', $logged);
    }

    public function testUserWithoutGetIdThrowsErrorWhenLoggingObjectToString(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'noid'; }
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $listener = $this->createListener($tokenStorage);

        $this->expectException(\Error::class);
        $listener->onKernelController($this->createControllerEvent('POST', 'body'));
    }

    public function testSkipsLoggingForGetMethod(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getId(): int { return 1; }
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'u1'; }
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $logger = new class extends NullLogger {
            public int $count = 0;
            public function info(string|\Stringable $message, array $context = []): void { $this->count++; }
        };

        $listener = $this->createListener($tokenStorage, $logger);
        $listener->onKernelController($this->createControllerEvent('GET'));

        self::assertSame(0, $logger->count);
    }

    public function testSkipsLoggingForDeleteMethod(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getId(): int { return 1; }
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'u1'; }
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $logger = new class extends NullLogger {
            public int $count = 0;
            public function info(string|\Stringable $message, array $context = []): void { $this->count++; }
        };

        $listener = $this->createListener($tokenStorage, $logger);
        $listener->onKernelController($this->createControllerEvent('DELETE'));

        self::assertSame(0, $logger->count);
    }
}
