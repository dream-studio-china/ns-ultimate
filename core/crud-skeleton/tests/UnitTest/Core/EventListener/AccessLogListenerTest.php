<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\EventListener;

use App\Core\EventListener\AccessLogListener;
use App\Identity\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class AccessLogListenerTest extends TestCase
{
    public function testLogsPostRequest(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $request = Request::create('/api/test', 'POST', [], [], [], [], '{"key":"value"}');
        $response = new Response('{"ok":true}', 201);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('@john#42', $logger->records[0]);
        self::assertStringContainsString('POST', $logger->records[0]);
        self::assertStringContainsString('/api/test', $logger->records[0]);
        self::assertStringContainsString('201', $logger->records[0]);
        self::assertStringContainsString('{"key":"value"}', $logger->records[0]);
        self::assertStringContainsString('{"ok":true}', $logger->records[0]);
    }

    public function testLogsPutRequest(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('jane', 7));

        $request = Request::create('/api/test/1', 'PUT', [], [], [], [], '{"updated":true}');
        $response = new Response('{"ok":true}', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('@jane#7', $logger->records[0]);
        self::assertStringContainsString('PUT', $logger->records[0]);
        self::assertStringContainsString('/api/test/1', $logger->records[0]);
    }

    public function testLogsDeleteRequest(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('admin', 1));

        $request = Request::create('/api/test/1', 'DELETE');
        $response = new Response('', 204);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('@admin#1', $logger->records[0]);
        self::assertStringContainsString('DELETE', $logger->records[0]);
    }

    public function testDoesNotLogGetRequest(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $request = Request::create('/api/test', 'GET');
        $response = new Response('{"data":[]}', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(0, $logger->records);
    }

    public function testEmptyRequestBodyLoggedAsEmpty(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $request = Request::create('/api/test', 'POST');
        $response = new Response('{"ok":true}', 201);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('REQ: (empty)', $logger->records[0]);
    }

    public function testEmptyResponseBodyLoggedAsEmpty(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $request = Request::create('/api/test', 'POST', [], [], [], [], '{"key":"value"}');
        $response = new Response('', 204);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('RES: (empty)', $logger->records[0]);
    }

    public function testStreamedResponseLoggedAsBinary(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $request = Request::create('/api/test', 'POST', [], [], [], [], '{"key":"value"}');
        $response = new StreamedResponse(function (): void {
            echo 'binary data';
        });
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('RES: (binary)', $logger->records[0]);
    }

    public function testTruncatesLongRequestBody(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $longBody = str_repeat('x', 5000);
        $request = Request::create('/api/test', 'POST', [], [], [], [], $longBody);
        $response = new Response('ok', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('...[truncated]', $logger->records[0]);
        self::assertLessThan(4300, strlen($logger->records[0]));
    }

    public function testTruncatesLongResponseBody(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $longBody = str_repeat('y', 5000);
        $request = Request::create('/api/test', 'POST');
        $response = new Response($longBody, 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('...[truncated]', $logger->records[0]);
    }

    public function testLogFormatContainsMethodUriStatusReqRes(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('alice', 99));

        $request = Request::create('/api/orders/1/payment', 'POST', [], [], [], [], '{"payment":"wechat"}');
        $response = new Response('{"status":"paying"}', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertSame(
            '@alice#99 POST /api/orders/1/payment | 200 | REQ: {"payment":"wechat"} | RES: {"status":"paying"}',
            $logger->records[0],
        );
    }

    public function testDoesNotLogPatchRequest(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('john', 42));

        $request = Request::create('/api/test', 'PATCH', [], [], [], [], '{"partial":true}');
        $response = new Response('ok', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(0, $logger->records);
    }

    public function testAnonymousUserShownAsAnon(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->emptyTokenStorage());

        $request = Request::create('/api/test', 'POST', [], [], [], [], '{}');
        $response = new Response('ok', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('(anon)', $logger->records[0]);
    }

    public function testAuthPathHidesRequestBody(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->emptyTokenStorage());

        $request = Request::create('/api/auth/login', 'POST', [], [], [], [], '{"password":"secret"}');
        $response = new Response('{"access_token":"token"}', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('REQ: (body hidden)', $logger->records[0]);
        self::assertStringContainsString('RES: (body hidden)', $logger->records[0]);
        self::assertStringNotContainsString('secret', $logger->records[0]);
        self::assertStringNotContainsString('access_token', $logger->records[0]);
    }

    public function testAuthPathHidesResponseBody(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->emptyTokenStorage());

        $request = Request::create('/api/auth/register', 'POST', [], [], [], [], '{"email":"x@y.com","password":"pwd"}');
        $response = new Response('{"access_token":"abc123","refresh_token":"def456"}', 201);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('REQ: (body hidden)', $logger->records[0]);
        self::assertStringContainsString('RES: (body hidden)', $logger->records[0]);
        self::assertStringNotContainsString('abc123', $logger->records[0]);
    }

    public function testWechatPathHidesBodies(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->emptyTokenStorage());

        $request = Request::create('/api/wechat/miniapp/login', 'POST', [], [], [], [], '{"code":"wx_code"}');
        $response = new Response('{"access_token":"token"}', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('REQ: (body hidden)', $logger->records[0]);
        self::assertStringContainsString('RES: (body hidden)', $logger->records[0]);
    }

    public function testWechatPathWithGetIsIgnored(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->emptyTokenStorage());

        $request = Request::create('/api/wechat/oauth/url', 'GET');
        $response = new Response('{"url":"https://..."}', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(0, $logger->records);
    }

    public function testNonAuthPathShowsBodiesAnyway(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('bob', 3));

        $request = Request::create('/api/v1/app/orders', 'POST', [], [], [], [], '{"items":[]}');
        $response = new Response('{"id":123}', 201);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('REQ: {"items":[]}', $logger->records[0]);
        self::assertStringContainsString('RES: {"id":123}', $logger->records[0]);
        self::assertStringNotContainsString('(body hidden)', $logger->records[0]);
    }

    public function testTokenWithNullUserShowsAnon(): void
    {
        $logger = new InMemoryLogger();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $listener = new AccessLogListener($logger, $this->tokenStorage($token));

        $request = Request::create('/api/test', 'POST');
        $response = new Response('ok', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('(anon)', $logger->records[0]);
    }

    public function testTokenWithUserWithoutGetIdShowsAnon(): void
    {
        $logger = new InMemoryLogger();
        $user = new class implements \Symfony\Component\Security\Core\User\UserInterface {
            public function getRoles(): array { return []; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'no-id-user'; }
        };
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $listener = new AccessLogListener($logger, $this->tokenStorage($token));

        $request = Request::create('/api/test', 'POST');
        $response = new Response('ok', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('(anon)', $logger->records[0]);
    }

    public function testUsernameFallsBackToEmail(): void
    {
        $logger = new InMemoryLogger();
        $listener = new AccessLogListener($logger, $this->tokenStorageWithUser('', 42));

        $request = Request::create('/api/test', 'POST');
        $response = new Response('ok', 200);
        $event = new ResponseEvent(
            $this->createStub(KernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        $listener->onKernelResponse($event);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('@user@example.com#42', $logger->records[0]);
    }

    private function tokenStorageWithUser(string $username, int $id): TokenStorageInterface
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail('user@example.com');
        $user->setPassword('ignored');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $this->tokenStorage($token);
    }

    /** @param object|null $user */
    private function tokenStorage(?TokenInterface $token): TokenStorageInterface
    {
        $storage = new class implements TokenStorageInterface {
            public ?TokenInterface $token = null;

            public function getToken(): ?TokenInterface
            {
                return $this->token;
            }

            public function setToken(?TokenInterface $token): void
            {
                $this->token = $token;
            }
        };

        $storage->setToken($token);

        return $storage;
    }

    private function emptyTokenStorage(): TokenStorageInterface
    {
        return $this->tokenStorage(null);
    }
}

final class InMemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = (string) $message;
    }
}
