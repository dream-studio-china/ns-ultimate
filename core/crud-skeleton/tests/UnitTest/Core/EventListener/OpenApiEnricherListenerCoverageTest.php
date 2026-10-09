<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\EventListener;

use App\Core\EventListener\OpenApiEnricherListener;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Covers the remaining branches of OpenApiEnricherListener (early-return guards).
 */
#[AllowMockObjectsWithoutExpectations]
final class OpenApiEnricherListenerCoverageTest extends TestCase
{
    private function createEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }

    public function testNonApiDocPathIsIgnored(): void
    {
        $listener = new OpenApiEnricherListener();
        $request = Request::create('/api/something-else', 'GET');
        $response = new Response('{"paths":{}}');
        $event = $this->createEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertSame('{"paths":{}}', $response->getContent());
    }

    public function testEmptyContentReturnsEarly(): void
    {
        $listener = new OpenApiEnricherListener();
        $request = Request::create('/api/doc.json', 'GET');
        $response = new Response('');
        $event = $this->createEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertSame('', $response->getContent());
    }

    public function testJsonContentWithoutPathsReturnsEarly(): void
    {
        $listener = new OpenApiEnricherListener();
        $request = Request::create('/api/doc.json', 'GET');
        $response = new Response('{"openapi":"3.0.0","info":{"title":"x"}}');
        $event = $this->createEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertSame('{"openapi":"3.0.0","info":{"title":"x"}}', $response->getContent());
    }

    public function testJsonArrayContentWithoutPathsReturnsEarly(): void
    {
        $listener = new OpenApiEnricherListener();
        $request = Request::create('/api/doc.json', 'GET');
        $response = new Response('[1,2,3]');
        $event = $this->createEvent($request, $response);

        $listener->onKernelResponse($event);

        self::assertSame('[1,2,3]', $response->getContent());
    }
}
