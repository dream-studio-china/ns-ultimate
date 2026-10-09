<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\EventListener;

use App\Core\EventListener\ExceptionInterceptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExceptionInterceptorExtendedTest extends TestCase
{
    private function createInterceptor(string $env = 'prod'): ExceptionInterceptor
    {
        $translator = new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return (string) $id;
            }
            public function getLocale(): string { return 'en'; }
        };

        return new ExceptionInterceptor(
            $translator,
            new NullLogger(),
            $env
        );
    }

    public function testDoesNotInterceptNonApiPath(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/other/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('should not be intercepted', 500)
        );

        $listener->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    public function testDoesNotInterceptRootPath(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('root exception', 500)
        );

        $listener->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    public function testDevEnvironmentSkipsInterception(): void
    {
        $listener = $this->createInterceptor('dev.disabled');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('dev exception', 500)
        );

        $listener->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    public function testUsesExceptionCodeAsHttpStatusWhenInRange(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('not found', 404)
        );

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testStatusCode400Boundary(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('bad request', 400)
        );

        $listener->onKernelException($event);

        self::assertSame(400, $event->getResponse()?->getStatusCode());
    }

    public function testStatusCode599Boundary(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('ok', 599)
        );

        $listener->onKernelException($event);

        self::assertSame(599, $event->getResponse()?->getStatusCode());
    }

    public function testDefaultsTo500WhenCodeBelow400(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('low code', 200)
        );

        $listener->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    public function testDefaultsTo500WhenCodeIsZero(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('zero code', 0)
        );

        $listener->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    public function testStatusCodeAt600Boundary(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('out of range', 600)
        );

        $listener->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    public function testResponseJsonStructure(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('struct test', 403)
        );

        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('code', $data);
        self::assertArrayHasKey('message', $data);
        self::assertArrayHasKey('class', $data);
        self::assertSame(403, $data['code']);
        self::assertSame('struct test', $data['message']);
        self::assertStringContainsString('RuntimeException', $data['class']);
    }

    public function testExceptionWithNegativeCodeDefaultsTo500(): void
    {
        $listener = $this->createInterceptor('prod');
        $event = new ExceptionEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('negative', -1)
        );

        $listener->onKernelException($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }
}
