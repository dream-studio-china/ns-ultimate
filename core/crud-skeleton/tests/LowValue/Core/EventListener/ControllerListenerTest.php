<?php

namespace App\Tests\LowValue\Core\EventListener;


use PHPUnit\Framework\Attributes\Group;
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

#[Group('low-value')]
final class ControllerListenerTest extends TestCase
{
    public function testLogsOnlyForWriteMethods(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new class implements UserInterface {
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'u1'; }
            public function getId(): int { return 1; }
        });

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $logger = new class extends NullLogger {
            public int $count = 0;
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->count++;
            }
        };

        $listener = new ControllerListener($tokenStorage, $logger);

        $kernel = $this->createStub(HttpKernelInterface::class);

        $listener->onKernelController(new ControllerEvent(
            $kernel,
            static fn (): string => 'ok',
            Request::create('/api/test', 'POST'),
            HttpKernelInterface::MAIN_REQUEST
        ));

        $listener->onKernelController(new ControllerEvent(
            $kernel,
            static fn (): string => 'ok',
            Request::create('/api/test', 'GET'),
            HttpKernelInterface::MAIN_REQUEST
        ));

        self::assertSame(1, $logger->count);
    }
}
