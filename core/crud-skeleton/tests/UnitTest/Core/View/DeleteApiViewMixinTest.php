<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseServiceInterface;
use App\Core\View\ApiView;
use App\Core\View\ApiViewMessages;
use App\Core\View\DeleteApiViewMixin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;

final class DeleteApiViewMixinTest extends TestCase
{
    private function createController(BaseServiceInterface $service, ?bool $nullFilter = false): object
    {
        $controller = new class($service, $nullFilter) extends RestController {
            use ApiView, DeleteApiViewMixin;

            protected BaseServiceInterface $service;
            private ?bool $nullFilter;

            public function __construct(BaseServiceInterface $service, ?bool $nullFilter)
            {
                $this->service = $service;
                $this->nullFilter = $nullFilter;
            }

            protected function deletionFilter(array|\Doctrine\ORM\QueryBuilder|null $filter = null)
            {
                return $this->nullFilter ? null : $filter;
            }
        };

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET'));
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));

        $controller->setContainer($container);
        $controller->setRequestStack($requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    public function testDeleteFilterNullReturnsNotFound(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->expects(self::never())->method('get');

        $response = $this->createController($service, true)->deleteAction(1);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString(ApiViewMessages::ENTITY_NOT_FOUND, $response->getContent());
    }

    public function testDeleteMissingEntityReturnsNotFound(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->expects(self::once())->method('get')->with(['id' => 1], false)->willReturn(null);

        $response = $this->createController($service)->deleteAction(1);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDeleteReturnsNoContentOnSuccess(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->with(['id' => 1], false)->willReturn(new \stdClass());
        $service->expects(self::once())->method('remove')->willReturn(true);

        $response = $this->createController($service)->deleteAction(1);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeleteReturnsWarningWhenRemoveFails(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->method('get')->with(['id' => 1], false)->willReturn(new \stdClass());
        $service->method('remove')->willReturn(false);

        $response = $this->createController($service)->deleteAction(1);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"code":-1', $response->getContent());
    }
}
