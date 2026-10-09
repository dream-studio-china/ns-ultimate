<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseServiceInterface;
use App\Core\View\ApiView;
use App\Core\View\SingleCreateAndUpdateApiViewMixin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

final class SingleCreateAndUpdateApiViewMixinCoverageTest extends TestCase
{
    private function createController(BaseServiceInterface $service): object
    {
        $controller = new class($service) extends RestController {
            use ApiView, SingleCreateAndUpdateApiViewMixin;

            protected BaseServiceInterface $service;

            public function __construct(BaseServiceInterface $service)
            {
                $this->service = $service;
            }

            protected function commonFilter(): array
            {
                return [];
            }
        };

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));
        $container->set('validator', Validation::createValidator());

        $controller->setContainer($container);
        $controller->setRequestStack($requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    public function testUpdateReturnsWarningWhenServiceReturnsFalse(): void
    {
        $service = $this->createStub(BaseServiceInterface::class);
        $service->method('get')->willReturn((object) ['id' => 1]);
        $service->method('update')->willReturn(false);

        $controller = $this->createController($service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"x"}');
        $response = $controller->updateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"code":-1', $response->getContent());
    }

    public function testUpdateReturnsNotFoundWhenServiceThrowsNotFound(): void
    {
        $service = $this->createStub(BaseServiceInterface::class);
        $service->method('get')->willReturn((object) ['id' => 1]);
        $service->method('update')->willThrowException(new NotFoundHttpException('The entity of key[user] is not found'));

        $controller = $this->createController($service);

        $request = Request::create('/', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"user":9}');
        $response = $controller->updateAction($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('The entity of key[user] is not found', $response->getContent());
    }
}
