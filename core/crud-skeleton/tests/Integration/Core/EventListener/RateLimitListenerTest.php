<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\EventListener;

use App\Core\EventListener\RateLimitListener;
use App\Tests\Integration\IntegrationWebTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * RateLimitListener behaviour: per-IP sliding-window limits on public
 * endpoints, 429 envelope + Retry-After when exceeded, passthrough for
 * non-listed paths. Uses a real RateLimiterFactory backed by an in-memory
 * storage so no global/test limits are touched.
 */
final class RateLimitListenerTest extends IntegrationWebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function buildListener(int $limit = 1): RateLimitListener
    {
        $storage = new InMemoryStorage();
        $factory = new RateLimiterFactory(
            ['id' => 'test_limiter', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'],
            $storage,
        );

        $locator = new ServiceLocator([
            'auth_login' => static fn (): RateLimiterFactory => $factory,
        ]);

        return new RateLimitListener(
            $locator,
            self::getContainer()->get(TranslatorInterface::class),
        );
    }

    private function makeEvent(string $path): ControllerEvent
    {
        $kernel = self::bootKernel();
        $request = Request::create($path, 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $controller = static fn (): Response => new Response('ok');

        return new ControllerEvent(
            $kernel,
            $controller,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    public function testRequestUnderLimitPassesThrough(): void
    {
        $listener = $this->buildListener(limit: 5);
        $event = $this->makeEvent('/api/auth/login');

        $listener->onKernelController($event);

        $response = ($event->getController())();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());
    }

    public function testRequestOverLimitGets429WithRetryAfter(): void
    {
        $listener = $this->buildListener(limit: 1);

        // First call consumes the only token.
        $listener->onKernelController($this->makeEvent('/api/auth/login'));

        // Second call within the window is rejected.
        $event = $this->makeEvent('/api/auth/login');
        $listener->onKernelController($event);

        $response = ($event->getController())();
        self::assertSame(429, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('Retry-After'));

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(429, $body['code']);
        self::assertNull($body['data']);
        self::assertNotEmpty($body['message']);
    }

    public function testDifferentClientIpsHaveIndependentLimits(): void
    {
        $listener = $this->buildListener(limit: 1);

        $listener->onKernelController($this->makeEvent('/api/auth/login'));

        // A different IP still has its own bucket.
        $event = $this->makeEvent('/api/auth/login');
        $event->getRequest()->server->set('REMOTE_ADDR', '203.0.113.8');
        $listener->onKernelController($event);

        $response = ($event->getController())();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testNonListedPathIsNeverLimited(): void
    {
        $listener = $this->buildListener(limit: 1);

        $event = $this->makeEvent('/api/v1/app/products');
        $listener->onKernelController($event);

        $response = ($event->getController())();
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPaymentEndpointIsMappedToPaymentLimiter(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'test_payment_limiter', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
        $locator = new ServiceLocator([
            'payment' => static fn (): RateLimiterFactory => $factory,
        ]);
        $listener = new RateLimitListener(
            $locator,
            self::getContainer()->get(TranslatorInterface::class),
        );

        $listener->onKernelController($this->makeEvent('/api/v1/app/orders/abc-123/payment'));
        $event = $this->makeEvent('/api/v1/app/orders/abc-123/payment');
        $listener->onKernelController($event);

        $response = ($event->getController())();
        self::assertSame(429, $response->getStatusCode());
    }

    public function testSubRequestIsSkipped(): void
    {
        $listener = $this->buildListener(limit: 1);
        $event = $this->makeEvent('/api/auth/login');
        $listener->onKernelController($event);

        // A sub-request must not consume a token.
        $kernel = self::bootKernel();
        $request = Request::create('/api/auth/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $sub = new ControllerEvent(
            $kernel,
            static fn (): Response => new Response('ok'),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );
        $listener->onKernelController($sub);

        $response = ($sub->getController())();
        self::assertSame(200, $response->getStatusCode());
    }
}
