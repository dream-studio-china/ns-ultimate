<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseServiceInterface;
use App\Core\View\ApiView;
use App\Core\View\ApiViewMessages;
use App\Core\View\CreateApiViewMixin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;

final class CreateApiViewMixinTest extends TestCase
{
    private function createController(CrudFakeService $service): object
    {
        $controller = new class($service) extends RestController {
            use ApiView, CreateApiViewMixin;

            protected CrudFakeService $service;

            public function __construct(CrudFakeService $service)
            {
                $this->service = $service;
            }
        };

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'POST'));
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));

        $controller->setContainer($container);
        $controller->setRequestStack($requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));
        $controller->setServiceContainer($container);

        return $controller;
    }

    public function testPartialCreateSkipsTransaction(): void
    {
        $service = new CrudFakeService();
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '[{"name":"a"}]',
        );
        $request->query->set('@partial', '1');
        $response = $controller->createAction($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertFalse($service->transactionRan, 'partial create must not wrap in a transaction');
        self::assertSame(1, $service->updateCalls);
    }

    public function testCreateWithEmptyTransformerKeepsContent(): void
    {
        $service = new CrudFakeService();
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"a"}',
        );
        $request->query->set('@transform', '{}');
        $response = $controller->createAction($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $service->updateCalls);
        self::assertSame(['name' => 'a'], $service->receivedData);
    }

    public function testCreateGenericExceptionReturnsCreateFailedMessage(): void
    {
        $service = new CrudFakeService();
        $service->updateException = new \RuntimeException();
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"a"}',
        );
        $response = $controller->createAction($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString(ApiViewMessages::CREATE_FAILED, $response->getContent());
    }

    public function testCreateGenericExceptionReturnsMessage(): void
    {
        $service = new CrudFakeService();
        $service->updateException = new \RuntimeException('unique key violated');
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '[{"name":"a"}]',
        );
        $response = $controller->createAction($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('unique key violated', $response->getContent());
    }

    public function testScalarJsonIsRejectedAsInvalidJson(): void
    {
        // '123' is valid JSON that is neither an object nor an array. The guard at
        // WorkflowApiViewMixin/CreateApiViewMixin.php:67 rejects it before the
        // dead `$contents = []` branch (line 93) can ever run.
        $service = new CrudFakeService();
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '123',
        );
        $response = $controller->createAction($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(ApiViewMessages::INVALID_JSON, $response->getContent());
        self::assertSame(0, $service->updateCalls);
    }
}

final class CrudFakeService implements BaseServiceInterface
{
    public ?object $getResult = null;
    public object $newResult;
    public \Throwable|null $updateException = null;
    public int $updateCalls = 0;
    public array $receivedData = [];
    public bool $transactionRan = false;

    public function __construct()
    {
        $this->newResult = new \stdClass();
    }

    public function get($object, bool $directly = false)
    {
        return $this->getResult;
    }

    public function list($object = null, $order = null, bool $disableRequest = true)
    {
        return [];
    }

    public function new()
    {
        return $this->newResult;
    }

    public function update($object, ?array $data = null, bool $noFlush = false)
    {
        ++$this->updateCalls;
        $this->receivedData = $data ?? [];

        if ($this->updateException !== null) {
            throw $this->updateException;
        }

        return $object;
    }

    public function remove($object): bool
    {
        return true;
    }

    public function wrapInTransaction(callable $fn): mixed
    {
        $this->transactionRan = true;

        return $fn(new \stdClass());
    }
}
