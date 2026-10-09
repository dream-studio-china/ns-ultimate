<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Controller\Manage;

use App\Promotion\Controller\Manage\PromotionController;
use App\Promotion\Service\PromotionServiceInterface;
use PHPUnit\Framework\Attributes\Group;
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

final class PromotionControllerTest extends TestCase
{
    private function createController(PromotionServiceInterface $service): PromotionController
    {
        $controller = new PromotionController($service);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
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

    /**
     * Security and transaction tests require kernel integration tests.
     * This unit test covers controller instantiation and basic wiring.
     */
    public function testControllerIsInstantiable(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = $this->createController($service);

        self::assertInstanceOf(PromotionController::class, $controller);
    }

    #[Group('low-value')]
    public function testAcceptedPropertiesAreDefined(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = new PromotionController($service);

        $ref = new \ReflectionClass($controller);
        self::assertTrue($ref->hasProperty('requiredCreateProperties'));
        self::assertTrue($ref->hasProperty('acceptedCreateProperties'));
        self::assertTrue($ref->hasProperty('acceptedUpdateProperties'));
    }

    public function testRequiredCreatePropertiesHaveCorrectValues(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = new PromotionController($service);

        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('requiredCreateProperties');
        $value = $prop->getValue($controller);

        self::assertContains('name', $value);
        self::assertContains('template', $value);
        self::assertNotContains('storeCode', $value);
    }

    public function testAcceptedPropertiesIncludeAllFields(): void
    {
        $service = $this->createMock(PromotionServiceInterface::class);
        $controller = new PromotionController($service);

        $ref = new \ReflectionClass($controller);

        $createProp = $ref->getProperty('acceptedCreateProperties');
        $createValue = $createProp->getValue($controller);

        $updateProp = $ref->getProperty('acceptedUpdateProperties');
        $updateValue = $updateProp->getValue($controller);

        self::assertContains('config', $createValue);
        self::assertContains('conflictMode', $createValue);
        self::assertContains('startTime', $createValue);
        self::assertContains('endTime', $createValue);

        self::assertContains('config', $updateValue);
        self::assertContains('conflictMode', $updateValue);
    }
}
