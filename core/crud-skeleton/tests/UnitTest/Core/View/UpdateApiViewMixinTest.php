<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Core\View;

use App\Core\Controller\RestController;
use App\Core\Service\BaseServiceInterface;
use App\Core\View\ApiView;
use App\Core\View\ApiViewMessages;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Exception\ValidatorException;

final class UpdateApiViewMixinTest extends TestCase
{
    private function createController(UpdateFakeService $service, array $config = []): object
    {
        $controller = new class($service) extends RestController {
            use ApiView, UpdateApiViewMixin, CreateApiViewMixin;

            protected UpdateFakeService $service;

            /** @var list<string> */
            public array $requiredUpdateProperties = [];

            /** @var list<string> */
            public array $acceptedUpdateProperties = [];

            public function __construct(UpdateFakeService $service)
            {
                $this->service = $service;
            }
        };

        foreach ($config as $prop => $value) {
            $controller->{$prop} = $value;
        }

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

    // ────────────── updateSingle: requiredUpdateProperties ──────────────

    public function testSingleUpdateMissingRequiredPropertyReturns400(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = new UpdateTestEntity();
        $controller = $this->createController($service, ['requiredUpdateProperties' => ['name']]);

        $request = Request::create('/api/things/1', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"other":"x"}');
        $response = $controller->updateAction($request, 1);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Name cannot be empty.', $response->getContent());
    }

    public function testSingleUpdateWithRequiredPropertyFiltersContent(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = new UpdateTestEntity();
        $controller = $this->createController($service, ['requiredUpdateProperties' => ['name']]);

        $request = Request::create('/api/things/1', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"ok","other":"x"}');
        $response = $controller->updateAction($request, 1);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('name', $service->receivedData);
        self::assertArrayNotHasKey('other', $service->receivedData);
    }

    // ────────────── updateSingle: transformer ──────────────

    public function testSingleUpdateAppliesTransformer(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = new UpdateTestEntity();
        $controller = $this->createController($service);

        $request = Request::create('/api/things/1', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"ok","note":"v"}');
        $request->query->set('@transform', '{"note":"abc"}');
        $response = $controller->updateAction($request, 1);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('note', $service->receivedData);
        self::assertSame('v', $service->receivedData['note']);
    }

    // ────────────── batch: non-partial, mode != mixed ──────────────

    public function testBatchUpdateContinuesWhenEntityMissingOutsideMixedMode(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = null;
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things/batch-update',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '[{"id":999,"name":"a"}]',
        );
        $request->query->set('@basis', 'id');
        $request->query->set('@mode', 'update');
        $response = $controller->batchUpdateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($service->transactionRan);
        self::assertSame(['id' => 999], $service->lastGetCriteria);
    }

    // ────────────── batch: partial skips failing items ──────────────

    public function testBatchPartialContinuesWhenEntityMissingOutsideMixedMode(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = null;
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things/batch-update',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '[{"id":999,"name":"a"}]',
        );
        $request->query->set('@basis', 'id');
        $request->query->set('@mode', 'update');
        $request->query->set('@partial', '1');
        $response = $controller->batchUpdateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($service->transactionRan);
        self::assertStringContainsString('"data":[]', $response->getContent());
    }

    public function testBatchPartialSkipsItemsThatFailValidation(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = null;
        $controller = $this->createController($service, ['requiredUpdateProperties' => ['name']]);

        $request = Request::create(
            '/api/things/batch-update',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '[{"id":999,"other":"bad"}]',
        );
        $request->query->set('@basis', 'id');
        $request->query->set('@partial', '1');
        $response = $controller->batchUpdateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($service->transactionRan);
        self::assertStringContainsString('"data":[]', $response->getContent());
    }

    // ────────────── batch: invalid content type ──────────────

    public function testBatchUpdateRejectsNonArrayContent(): void
    {
        $service = new UpdateFakeService();
        $controller = $this->createController($service);

        $request = Request::create(
            '/api/things/batch-update',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '"hello"',
        );

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage(ApiViewMessages::CONTENT_TYPE_ERROR);
        $controller->batchUpdateAction($request);
    }

    // ────────────── updateAction: generic exceptions ──────────────

    public function testSingleUpdateGenericExceptionReturns500WithMessage(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = new UpdateTestEntity();
        $service->updateException = new \RuntimeException('flush failed');
        $controller = $this->createController($service);

        $request = Request::create('/api/things/1', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"ok"}');
        $response = $controller->updateAction($request, 1);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('flush failed', $response->getContent());
    }

    public function testSingleUpdateGenericExceptionFallsBackToUnknownError(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = new UpdateTestEntity();
        $service->updateException = new \RuntimeException();
        $controller = $this->createController($service);

        $request = Request::create('/api/things/1', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"ok"}');
        $response = $controller->updateAction($request, 1);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString(RestController::UNKNOWN_ERROR, $response->getContent());
    }

    // ────────────── updateAction: falsy response ──────────────

    public function testSingleUpdateFalsyResultReturnsWarning(): void
    {
        $service = new UpdateFakeService();
        $service->getResult = new UpdateTestEntity();
        $service->updateResult = false;
        $controller = $this->createController($service);

        $request = Request::create('/api/things/1', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"ok"}');
        $response = $controller->updateAction($request, 1);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"code":-1', $response->getContent());
    }
}

final class UpdateFakeService implements BaseServiceInterface
{
    public ?object $getResult = null;
    public ?object $newResult = null;
    public mixed $updateResult = null;
    public \Throwable|null $updateException = null;
    public array $receivedData = [];
    public ?array $lastGetCriteria = null;
    public bool $transactionRan = false;

    public function get($object, bool $directly = false)
    {
        $this->lastGetCriteria = is_array($object) ? $object : null;

        return $this->getResult;
    }

    public function list($object = null, $order = null, bool $disableRequest = true)
    {
        return [];
    }

    public function new()
    {
        return $this->newResult ?? new UpdateTestEntity();
    }

    public function update($object, ?array $data = null, bool $noFlush = false)
    {
        $this->receivedData = $data ?? [];

        if ($this->updateException !== null) {
            throw $this->updateException;
        }

        return $this->updateResult ?? $object;
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

final class UpdateTestEntity
{
    public ?string $name = null;
    public ?string $note = null;

    public function getId(): int
    {
        return 1;
    }
}
