<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\Controller\System;

use App\Core\Controller\System\RouterController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RouterControllerExtendedTest extends TestCase
{
    private function s(): SerializerInterface
    {
        return new class implements SerializerInterface {
            public function serialize(mixed $data, string $format, array $context = []): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
            public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
            {
                return null;
            }
        };
    }

    private function t(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(?string $id, array $p = [], ?string $d = null, ?string $l = null): string
            {
                return (string) $id;
            }
            public function getLocale(): string { return 'en'; }
        };
    }

    private function createController(Request $request, RouterInterface $router): RouterController
    {
        $stack = new RequestStack();
        $stack->push($request);

        $controller = new RouterController($router);
        $controller->setRequestStack($stack);
        $controller->setSerializer($this->s());
        $controller->setTranslator($this->t());

        return $controller;
    }

    #[Group('low-value')]
    public function testListActionWithRoutesHavingDifferentMethods(): void
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('read', new Route('/read', defaults: ['_controller' => 'c'], methods: ['GET']));
        $routeCollection->add('write', new Route('/write', defaults: ['_controller' => 'c'], methods: ['POST']));
        $routeCollection->add('mixed', new Route('/mixed', defaults: ['_controller' => 'c'], methods: ['GET', 'POST', 'PUT', 'DELETE']));

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routeCollection);

        $controller = $this->createController(Request::create('/', 'GET'), $router);
        $response = $controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(3, $decoded['data']);
        self::assertArrayHasKey('read', $decoded['data']);
        self::assertArrayHasKey('write', $decoded['data']);
        self::assertArrayHasKey('mixed', $decoded['data']);
    }

    #[Group('low-value')]
    public function testListActionWithRoutesHavingDefaults(): void
    {
        $route = new Route('/api/items/{id}', defaults: [
            '_controller' => 'itemController',
            'page' => 1,
            'limit' => 10,
        ]);

        $routeCollection = new RouteCollection();
        $routeCollection->add('items', $route);

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routeCollection);

        $controller = $this->createController(Request::create('/', 'GET'), $router);
        $response = $controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded['data']);
        self::assertArrayHasKey('items', $decoded['data']);
    }

    public function testListActionResponseIsJson(): void
    {
        $routeCollection = new RouteCollection();
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routeCollection);

        $controller = $this->createController(Request::create('/', 'GET'), $router);
        $response = $controller->listAction();

        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertJson((string) $response->getContent());
    }

    #[Group('low-value')]
    public function testListActionWithComplexRouteRequirements(): void
    {
        $route = new Route('/entity/{entityName}', defaults: ['_controller' => 'c'], requirements: [
            'entityName' => '.+',
        ]);

        $routeCollection = new RouteCollection();
        $routeCollection->add('system-entity-retrieve', $route);

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routeCollection);

        $controller = $this->createController(Request::create('/', 'GET'), $router);
        $response = $controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('system-entity-retrieve', $decoded['data']);
    }

    #[Group('low-value')]
    public function testListActionWithManyRoutes(): void
    {
        $routeCollection = new RouteCollection();
        for ($i = 0; $i < 50; $i++) {
            $routeCollection->add("route_$i", new Route("/api/$i"));
        }

        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routeCollection);

        $controller = $this->createController(Request::create('/', 'GET'), $router);
        $response = $controller->listAction();
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(50, $decoded['data']);
    }
}
