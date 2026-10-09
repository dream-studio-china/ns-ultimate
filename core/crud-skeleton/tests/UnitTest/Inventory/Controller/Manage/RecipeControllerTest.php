<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Inventory\Controller\Manage;

use App\Inventory\Controller\Manage\RecipeController;
use App\Inventory\Entity\SpecificationRecipe;
use App\Inventory\Service\SpecificationRecipeServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class RecipeControllerTest extends TestCase
{
    private SpecificationRecipeServiceInterface $service;
    private RecipeController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(SpecificationRecipeServiceInterface::class);
        $this->controller = new RecipeController($this->service);
    }

    private function injectDependencies(RequestStack $requestStack): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn ($data, $format) => json_encode($data, JSON_THROW_ON_ERROR)
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->controller->setRequestStack($requestStack);
        $this->controller->setSerializer($serializer);
        $this->controller->setTranslator($translator);
    }

    private function jsonRequest(string $method, string $uri, array $payload): Request
    {
        return Request::create(
            $uri,
            $method,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public function testCreateActionReturns201WhenRecipeCreated(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000001',
            'lines' => [
                ['materialUuid' => '00000000-0000-4000-8000-000000000002', 'quantityPerUnit' => '1.000000'],
            ],
        ]));
        $this->injectDependencies($requestStack);

        $recipe = new SpecificationRecipe('00000000-0000-4000-8000-000000000001');
        $this->service->expects(self::once())->method('createRecipe')->with(
            '00000000-0000-4000-8000-000000000001',
            [['materialUuid' => '00000000-0000-4000-8000-000000000002', 'quantityPerUnit' => '1.000000']],
        )->willReturn($recipe);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Success', $body['message']);
    }

    public function testCreateActionForwardsIntegerSortToService(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000001',
            'lines' => [
                ['materialUuid' => '00000000-0000-4000-8000-000000000002', 'quantityPerUnit' => '2.000000', 'sort' => 5],
            ],
        ]));
        $this->injectDependencies($requestStack);

        $recipe = new SpecificationRecipe('00000000-0000-4000-8000-000000000001');
        $this->service->method('createRecipe')->with(
            '00000000-0000-4000-8000-000000000001',
            [['materialUuid' => '00000000-0000-4000-8000-000000000002', 'quantityPerUnit' => '2.000000', 'sort' => 5]],
        )->willReturn($recipe);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreateActionRejectsMissingSpecificationOrEmptyLines(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000001',
            'lines' => [],
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('specificationUuid and non-empty lines are required.', $body['message']);

        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/recipes', ['lines' => [['x' => 'y']]]));
        $this->injectDependencies($requestStack);
        $response = $this->controller->createAction($requestStack->getCurrentRequest());
        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateActionRejectsLineMissingMaterialUuid(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000001',
            'lines' => [['quantityPerUnit' => '1.000000']],
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Each recipe line requires materialUuid and quantityPerUnit.', $body['message']);
    }

    public function testCreateActionRejectsNonIntegerSort(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000001',
            'lines' => [
                ['materialUuid' => '00000000-0000-4000-8000-000000000002', 'quantityPerUnit' => '1.000000', 'sort' => '5'],
            ],
        ]));
        $this->injectDependencies($requestStack);

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Recipe line sort must be an integer.', $body['message']);
    }

    public function testCreateActionReturnsWarningWhenServiceThrows(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push($this->jsonRequest('POST', '/api/v1/manage/inventory/recipes', [
            'specificationUuid' => '00000000-0000-4000-8000-000000000001',
            'lines' => [['materialUuid' => '00000000-0000-4000-8000-000000000002', 'quantityPerUnit' => '1.000000']],
        ]));
        $this->injectDependencies($requestStack);

        $this->service->method('createRecipe')->willThrowException(new \LogicException('A recipe already exists for this specification.'));

        $response = $this->controller->createAction($requestStack->getCurrentRequest());

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('A recipe already exists for this specification.', $body['message']);
    }

    #[Group('low-value')]
    public function testCreateHooksUsedByCreateModeReturnPassThroughValues(): void
    {
        $recipe = new SpecificationRecipe('00000000-0000-4000-8000-000000000099');

        $defaults = $this->invokeProtected('defaultCreateValues');
        self::assertSame([], $defaults);

        $processed = $this->invokeProtected('processCreateContent', ['specificationUuid' => 'x'], $recipe);
        self::assertSame(['specificationUuid' => 'x'], $processed);

        $after = $this->invokeProtected('afterCreated', $recipe);
        self::assertSame($recipe, $after);
    }

    private function invokeProtected(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(RecipeController::class, $method);

        return $reflection->invoke($this->controller, ...$args);
    }
}
