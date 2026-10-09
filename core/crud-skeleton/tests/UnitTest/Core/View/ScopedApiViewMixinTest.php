<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseServiceInterface;
use App\Core\View\ApiView;
use App\Core\View\ScopedDetailApiViewMixin;
use App\Core\View\ScopedListApiViewMixin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;

final class ScopedApiViewMixinTest extends TestCase
{
    public function testListUsesScopeFilterWithoutDynamicQueryOptions(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->expects(self::once())->method('list')->with(['storeUuid' => 'store-1'], null, false)->willReturn([]);
        $controller = $this->createController($service);

        $response = $controller->listAction('store-1');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDetailUsesScopeFilterAndReturnsNotFoundWhenAbsent(): void
    {
        $service = $this->createMock(BaseServiceInterface::class);
        $service->expects(self::once())->method('get')->with(['storeUuid' => 'store-1', 'uuid' => 'order-1'], false)->willReturn(null);
        $controller = $this->createController($service);

        $response = $controller->detailAction('store-1', 'order-1');

        self::assertSame(404, $response->getStatusCode());
    }

    private function createController(BaseServiceInterface $service): object
    {
        $controller = new class($service) extends RestController {
            use ApiView, ScopedListApiViewMixin, ScopedDetailApiViewMixin;

            protected BaseServiceInterface $service;

            public function __construct(BaseServiceInterface $service)
            {
                $this->service = $service;
            }

            protected function scopedListFilter(string $scopeId): array
            {
                return ['storeUuid' => $scopeId];
            }

            protected function scopedDetailFilter(string $scopeId, string $id): array
            {
                return ['storeUuid' => $scopeId, 'uuid' => $id];
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
}
