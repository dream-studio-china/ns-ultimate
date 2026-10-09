<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Controller\System;

use App\Core\Controller\System\RouterController;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class RouterControllerTest extends TestCase
{
    private RouterInterface $router;
    private RequestStack $requestStack;
    private SerializerInterface $serializer;
    private TranslatorInterface $translator;
    private RouterController $controller;

    protected function setUp(): void
    {
        $this->router = $this->createMock(RouterInterface::class);
        $this->requestStack = new RequestStack();
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->requestStack->push(Request::create('/system/router', 'GET'));
        $this->serializer->method('serialize')->willReturnCallback(
            fn($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $this->translator->method('trans')->willReturnArgument(0);

        $this->controller = new RouterController($this->router);
    }

    private function injectDependencies(): void
    {
        if (method_exists($this->controller, 'setRequestStack')) {
            $this->controller->setRequestStack($this->requestStack);
        }
        if (method_exists($this->controller, 'setSerializer')) {
            $this->controller->setSerializer($this->serializer);
        }
        if (method_exists($this->controller, 'setTranslator')) {
            $this->controller->setTranslator($this->translator);
        }
    }

    public function testListActionReturnsAllRoutes(): void
    {
        $this->injectDependencies();

        $route1 = new Route('/api/categories', defaults: ['_controller' => 'categoryController']);
        $route2 = new Route('/api/tags', defaults: ['_controller' => 'tagController']);

        $routeCollection = new RouteCollection();
        $routeCollection->add('api-categories', $route1);
        $routeCollection->add('api-tags', $route2);

        $this->router->method('getRouteCollection')->willReturn($routeCollection);

        $response = $this->controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $decoded['code']);
        self::assertArrayHasKey('api-categories', $decoded['data']);
        self::assertArrayHasKey('api-tags', $decoded['data']);
    }

    public function testListActionReturnsEmptyObjectWhenNoRoutes(): void
    {
        $this->injectDependencies();

        $routeCollection = new RouteCollection();
        $this->router->method('getRouteCollection')->willReturn($routeCollection);

        $response = $this->controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $decoded['data']);
    }

    public function testListActionResponseStructure(): void
    {
        $this->injectDependencies();

        $routeCollection = new RouteCollection();
        $this->router->method('getRouteCollection')->willReturn($routeCollection);

        $response = $this->controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('code', $decoded);
        self::assertArrayHasKey('message', $decoded);
        self::assertSame(0, $decoded['code']);
        self::assertSame('SUCCESS', $decoded['message']);
    }
}
