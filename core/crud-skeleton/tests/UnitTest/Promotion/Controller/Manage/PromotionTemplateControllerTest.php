<?php /** @noinspection PhpMissingParentConstructorInspection */

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Controller\Manage;

use App\Promotion\Controller\Manage\PromotionTemplateController;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionTemplateServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

final class PromotionTemplateControllerTest extends TestCase
{
    private RequestStack $requestStack;

    private function createController(PromotionTemplateServiceInterface $service): PromotionTemplateController
    {
        $controller = new PromotionTemplateController($service);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $this->requestStack = new RequestStack();

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', $this->requestStack);
        $container->set('serializer', $serializer);
        $container->set('translator', new Translator('en'));
        $container->set('validator', Validation::createValidator());

        $controller->setContainer($container);
        $controller->setRequestStack($this->requestStack);
        $controller->setSerializer($serializer);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    private function createTemplate(string $name = 'Test Template', string $type = PromotionTemplate::TYPE_FULL_REDUCTION, string $dsl = ''): PromotionTemplate
    {
        $template = new PromotionTemplate();
        $template->setName($name);
        $template->setType($type);
        $template->setDsl($dsl);
        return $template;
    }

    // ──────────────────── validateAction ────────────────────

    public function testValidateActionReturnsSuccessForValidDsl(): void
    {
        $template = $this->createTemplate(
            'Valid Template',
            PromotionTemplate::TYPE_FULL_REDUCTION,
            "type: full_reduction\ndo:\n  discount order 10.00"
        );

        $service = $this->createMock(PromotionTemplateServiceInterface::class);
        $service->method('get')->with(1)->willReturn($template);
        $service->method('parseDsl')->with($template->getDsl())->willReturn([
            'ast' => ['type' => 'program', 'data' => ['type' => 'full_reduction'], 'children' => []],
            'errors' => [],
        ]);

        $controller = $this->createController($service);

        $request = Request::create('/manage/promotion-templates/1/validate', 'POST');
        $this->requestStack->push($request);
        $response = $controller->validateAction(1);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testValidateActionReturnsErrorForInvalidDsl(): void
    {
        $template = $this->createTemplate('Invalid Template', PromotionTemplate::TYPE_DISCOUNT, '@invalid!!!');

        $service = $this->createMock(PromotionTemplateServiceInterface::class);
        $service->method('get')->with(2)->willReturn($template);
        $service->method('parseDsl')->with($template->getDsl())->willReturn([
            'ast' => null,
            'errors' => [
                ['line' => 1, 'col' => 1, 'message' => 'Unexpected character'],
            ],
        ]);

        $controller = $this->createController($service);

        $request = Request::create('/manage/promotion-templates/2/validate', 'POST');
        $this->requestStack->push($request);
        $response = $controller->validateAction(2);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testValidateActionReturns404WhenTemplateNotFound(): void
    {
        $service = $this->createMock(PromotionTemplateServiceInterface::class);
        $service->method('get')->with(999)->willReturn(null);

        $controller = $this->createController($service);

        $request = Request::create('/manage/promotion-templates/999/validate', 'POST');
        $this->requestStack->push($request);
        $response = $controller->validateAction(999);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testValidateActionRejectsNonPromotionTemplateResult(): void
    {
        $service = $this->createMock(PromotionTemplateServiceInterface::class);
        $service->method('get')->with(5)->willReturn(new \stdClass());

        $controller = $this->createController($service);

        $request = Request::create('/manage/promotion-templates/5/validate', 'POST');
        $this->requestStack->push($request);
        $response = $controller->validateAction(5);

        self::assertSame(404, $response->getStatusCode());
    }

    // ──────────────────── dryRunAction ────────────────────

    public function testDryRunActionReturnsSimulationResult(): void
    {
        $template = $this->createTemplate('Dry Run Template', PromotionTemplate::TYPE_FULL_REDUCTION);

        $simResult = [
            'template_id' => null,
            'type' => PromotionTemplate::TYPE_FULL_REDUCTION,
            'dsl' => '',
            'sampleContext' => [],
            'matched' => false,
            'actions' => [],
        ];

        $service = $this->createMock(PromotionTemplateServiceInterface::class);
        $service->method('get')->with(3)->willReturn($template);
        $service->method('simulate')->with($template, [])->willReturn($simResult);

        $controller = $this->createController($service);

        $request = Request::create('/manage/promotion-templates/3/dry-run', 'POST');
        $this->requestStack->push($request);
        $response = $controller->dryRunAction(3);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDryRunActionReturns404WhenTemplateNotFound(): void
    {
        $service = $this->createMock(PromotionTemplateServiceInterface::class);
        $service->method('get')->with(999)->willReturn(null);

        $controller = $this->createController($service);

        $request = Request::create('/manage/promotion-templates/999/dry-run', 'POST');
        $this->requestStack->push($request);
        $response = $controller->dryRunAction(999);

        self::assertSame(404, $response->getStatusCode());
    }

    // ──────────────────── property declarations ────────────────────

    public function testRequiredAndAcceptedPropertiesAreDefined(): void
    {
        $service = $this->createMock(PromotionTemplateServiceInterface::class);
        $controller = new PromotionTemplateController($service);

        $ref = new \ReflectionClass($controller);

        self::assertTrue($ref->hasProperty('requiredCreateProperties'));
        self::assertTrue($ref->hasProperty('acceptedCreateProperties'));
        self::assertTrue($ref->hasProperty('acceptedUpdateProperties'));

        $req = $ref->getProperty('requiredCreateProperties');
        self::assertContains('name', $req->getValue($controller));
        self::assertContains('type', $req->getValue($controller));
        self::assertContains('dsl', $req->getValue($controller));

        $acc = $ref->getProperty('acceptedCreateProperties');
        self::assertContains('phase', $acc->getValue($controller));
        self::assertContains('fields', $acc->getValue($controller));
    }
}
